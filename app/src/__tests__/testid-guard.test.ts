/**
 * Test-ID guard (design-system precondition, see
 * the design-system app inventory, sections 4 and 7).
 *
 * Every interactive element in core/app/src/pages, core/app/src/components and
 * core/packages/ui/src/ds must carry a stable, kebab-case test id (`data-testid`
 * or the ds `testId` prop), or forward a `{...rest}` / `{...props}` spread to the
 * DOM. Violations that exist today are frozen in `testid-baseline.json`. The
 * guard fails for every NEW violation and for every baseline entry that no longer
 * matches a violation, so the baseline can only shrink.
 *
 * Baseline keys are line-insensitive: file + element + the sorted attribute names
 * (class/className/style excluded, so restyling does not churn the baseline).
 *
 * Regenerate the baseline deliberately (review the diff!) with:
 *   UPDATE_TESTID_BASELINE=1 npx vitest run src/__tests__/testid-guard.test.ts
 */

import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'
import { describe, expect, it } from 'vitest'

const testDir = dirname(fileURLToPath(import.meta.url))
// core/app/src/__tests__ -> core
const coreRoot = resolve(testDir, '..', '..', '..')
const baselinePath = join(testDir, 'testid-baseline.json')
const scanRoots = [
  join(coreRoot, 'app/src/pages'),
  join(coreRoot, 'app/src/components'),
  join(coreRoot, 'packages/ui/src/ds'),
]

/** Intrinsic elements that are interactive wherever they appear. */
const NATIVE_INTERACTIVE = new Set(['button', 'input', 'select', 'textarea'])
/** Components (legacy ui + ds) that render an interactive control. */
const COMPONENT_INTERACTIVE = new Set([
  'Button',
  'IconButton',
  'TextInput',
  'TextField',
  'TextArea',
  'Textarea',
  'Select',
  'Checkbox',
  'Switch',
  'VoteControl',
])
const TEST_ID_ATTRS = new Set(['data-testid', 'testId'])
/** Spreads of these identifiers forward the caller's props (and their test id). */
const PASS_THROUGH_SPREADS = new Set(['rest', 'props'])
const KEBAB_CASE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/
/** Attributes restyling is expected to change; not part of the baseline key. */
const UNSTABLE_ATTRS = new Set(['className', 'class', 'style'])

interface Violation {
  /** Path relative to core/, with forward slashes. */
  file: string
  element: string
  /** Line-insensitive signature (see header). */
  key: string
  line: number
  message: string
}

interface BaselineEntry {
  file: string
  element: string
  key: string
}

/** Stable identity used for matching against the baseline (no line numbers). */
function identity(entry: BaselineEntry): string {
  return `${entry.file}\u0000${entry.element}\u0000${entry.key}`
}

/** Static string values an expression can take; null when only known at runtime. */
function literalValues(expr: ts.Expression): string[] | null {
  if (ts.isStringLiteral(expr) || ts.isNoSubstitutionTemplateLiteral(expr)) return [expr.text]
  if (ts.isTemplateExpression(expr)) {
    // Each `${...}` placeholder is treated as one kebab-safe character.
    const parts = [expr.head.text, ...expr.templateSpans.map((span) => span.literal.text)]
    return [parts.join('x')]
  }
  if (ts.isParenthesizedExpression(expr)) return literalValues(expr.expression)
  if (ts.isConditionalExpression(expr)) {
    const whenTrue = literalValues(expr.whenTrue)
    const whenFalse = literalValues(expr.whenFalse)
    return whenTrue && whenFalse ? [...whenTrue, ...whenFalse] : null
  }
  return null
}

function checkElement(
  node: ts.JsxOpeningElement | ts.JsxSelfClosingElement,
  sourceFile: ts.SourceFile,
  file: string,
): Violation | null {
  const element = node.tagName.getText(sourceFile)
  const attrs = node.attributes.properties
  const jsxAttrs = attrs.filter(ts.isJsxAttribute)
  const attrName = (attr: ts.JsxAttribute) => attr.name.getText(sourceFile)

  const hasOnClick = jsxAttrs.some((attr) => attrName(attr) === 'onClick')
  const isInteractive =
    NATIVE_INTERACTIVE.has(element) ||
    (element === 'a' && hasOnClick) ||
    COMPONENT_INTERACTIVE.has(element)
  if (!isInteractive) return null

  const typeAttr = jsxAttrs.find((attr) => attrName(attr) === 'type')?.initializer
  if (element === 'input' && typeAttr && ts.isStringLiteral(typeAttr) && typeAttr.text === 'hidden')
    return null

  const key = jsxAttrs
    .map(attrName)
    .filter((name) => !UNSTABLE_ATTRS.has(name))
    .sort()
    .join(',')
  const line = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line + 1
  const violation = (message: string): Violation => ({ file, element, key, line, message })

  const forwardsProps = attrs.some(
    (attr) =>
      ts.isJsxSpreadAttribute(attr) &&
      ts.isIdentifier(attr.expression) &&
      PASS_THROUGH_SPREADS.has(attr.expression.text),
  )
  if (forwardsProps) return null

  const testIdAttr = jsxAttrs.find((attr) => TEST_ID_ATTRS.has(attrName(attr)))
  if (!testIdAttr) return violation(`<${element}> has no test id (data-testid or testId)`)

  const init = testIdAttr.initializer
  if (!init) return violation(`<${element}> has an empty ${attrName(testIdAttr)}`)
  const values = ts.isStringLiteral(init)
    ? [init.text]
    : ts.isJsxExpression(init) && init.expression
      ? literalValues(init.expression)
      : null
  // Runtime-only values (identifiers, calls) cannot be checked statically; the
  // component that receives them is responsible for the id.
  const bad = values?.find((value) => !KEBAB_CASE.test(value))
  if (bad !== undefined) return violation(`<${element}> test id "${bad}" is not kebab-case`)
  return null
}

/** All interactive-element violations in one source file (before the baseline). */
function scanSource(file: string, text: string): Violation[] {
  const sourceFile = ts.createSourceFile(
    file,
    text,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  )
  const found: Violation[] = []
  const visit = (node: ts.Node): void => {
    if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
      const violation = checkElement(node, sourceFile, file)
      if (violation) found.push(violation)
    }
    ts.forEachChild(node, visit)
  }
  visit(sourceFile)
  return found
}

function listSourceFiles(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) out.push(...listSourceFiles(path))
    else if (entry.name.endsWith('.tsx') && !entry.name.endsWith('.test.tsx')) out.push(path)
  }
  return out.sort()
}

function scanAll(): { fileCount: number; violations: Violation[] } {
  const files = scanRoots.flatMap(listSourceFiles)
  const violations = files.flatMap((path) => {
    const rel = relative(coreRoot, path).split(sep).join('/')
    return scanSource(rel, readFileSync(path, 'utf8'))
  })
  return { fileCount: files.length, violations }
}

function loadBaseline(): BaselineEntry[] {
  const parsed: { entries: BaselineEntry[] } = JSON.parse(readFileSync(baselinePath, 'utf8'))
  return parsed.entries
}

/** Violations not covered by the baseline, and baseline entries no longer found. */
function diffAgainstBaseline(
  violations: Violation[],
  baseline: BaselineEntry[],
): { newViolations: Violation[]; staleEntries: BaselineEntry[] } {
  const remaining = new Map<string, number>()
  for (const entry of baseline) {
    remaining.set(identity(entry), (remaining.get(identity(entry)) ?? 0) + 1)
  }
  const newViolations: Violation[] = []
  for (const violation of violations) {
    const id = identity(violation)
    const left = remaining.get(id) ?? 0
    if (left > 0) remaining.set(id, left - 1)
    else newViolations.push(violation)
  }
  const staleEntries: BaselineEntry[] = []
  for (const entry of baseline) {
    const id = identity(entry)
    const left = remaining.get(id) ?? 0
    if (left > 0) {
      staleEntries.push(entry)
      remaining.set(id, left - 1)
    }
  }
  return { newViolations, staleEntries }
}

function describeViolation(v: Violation): string {
  return `${v.file}:${v.line} ${v.message}`
}

describe('test-id scanner', () => {
  it('flags an interactive element without a test id', () => {
    const found = scanSource('x.tsx', 'const a = <button onClick={f}>Go</button>')
    expect(found).toHaveLength(1)
    expect(found[0]).toMatchObject({ element: 'button', key: 'onClick' })
  })

  it('accepts data-testid, the testId prop and rest/props pass-through', () => {
    const source = `
      const a = <button data-testid="board-save">Save</button>
      const b = <Button testId="board-save" variant="primary" />
      const c = <button {...rest} />
      const d = <TextInput {...props} />
    `
    expect(scanSource('x.tsx', source)).toEqual([])
  })

  it('rejects non-kebab-case ids and checks template-literal prefixes', () => {
    const bad = scanSource('x.tsx', '<Button testId="Board_Save" />')
    expect(bad.map((v) => v.message)).toEqual(['<Button> test id "Board_Save" is not kebab-case'])
    const good = scanSource('x.tsx', `<button data-testid={\`form-day-\${day}\`} />`)
    expect(good).toEqual([])
  })

  it('only treats anchors with onClick and non-hidden inputs as interactive', () => {
    const source = `
      const a = <a href="/x">plain link</a>
      const b = <a href="/x" onClick={f}>handler link</a>
      const c = <input type="hidden" name="csrf" />
      const d = <input type="text" />
    `
    expect(scanSource('x.tsx', source).map((v) => v.element)).toEqual(['a', 'input'])
  })
})

describe('test-id baseline diff', () => {
  const entry = { file: 'app/src/pages/A.tsx', element: 'button', key: 'onClick,type' }
  const violation = { ...entry, line: 12, message: 'no test id' }

  it('matches a violation to its entry regardless of line number', () => {
    const { newViolations, staleEntries } = diffAgainstBaseline(
      [{ ...violation, line: 99 }],
      [entry],
    )
    expect(newViolations).toEqual([])
    expect(staleEntries).toEqual([])
  })

  it('reports a violation with no baseline entry as new', () => {
    const { newViolations } = diffAgainstBaseline([violation], [])
    expect(newViolations).toEqual([violation])
  })

  it('reports a baseline entry without a matching violation as stale', () => {
    const { staleEntries } = diffAgainstBaseline([], [entry, entry])
    expect(staleEntries).toEqual([entry, entry])
  })
})

describe('test-id guard', () => {
  it('fails for new violations and for stale baseline entries', () => {
    const { fileCount, violations } = scanAll()
    expect(fileCount).toBeGreaterThan(0)

    if (process.env.UPDATE_TESTID_BASELINE === '1') {
      const entries = violations
        .map(({ file, element, key }) => ({ file, element, key }))
        .sort((a, b) => identity(a).localeCompare(identity(b)))
      writeFileSync(baselinePath, `${JSON.stringify({ entries }, null, 2)}\n`, 'utf8')
      console.info(`[testid-guard] baseline regenerated: ${entries.length} entries`)
      return
    }

    const baseline = loadBaseline()
    console.info(
      `[testid-guard] baseline: ${baseline.length} entries (${violations.length} current violations, ${fileCount} files scanned)`,
    )
    const { newViolations, staleEntries } = diffAgainstBaseline(violations, baseline)
    expect(newViolations.map(describeViolation)).toEqual([])
    expect(
      staleEntries.map((e) => `${e.file} <${e.element}> ${e.key} (no longer violating, remove it)`),
    ).toEqual([])
  })
})

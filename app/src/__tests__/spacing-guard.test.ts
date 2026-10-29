/**
 * Spacing guard: keeps container padding on the design-system tokens.
 *
 * Two rules, scanning core/app/src and, when present (monorepo only), cloud/app
 * (tests excluded). The Community export has no cloud/ folder, so the guard
 * scans core/app/src alone there and uses the same file keys.
 *
 * 1. Flush sections. `<Section flush>` removes the section body padding, so
 *    every direct child must bring its own DS spacing (`px-ds-md sm:px-ds-lg`,
 *    `p-ds-md`, …) or a DS class (`ds-*`), or be a component. A host child with
 *    neither (raw `divide-y` rows, `px-4` wrappers) is an offender. The
 *    offending sections are listed in `FLUSH_ALLOWLIST` with a reason; the list
 *    can only shrink (a section that is fixed must be removed from it).
 *
 * 2. Ratchet. Raw Tailwind padding utilities (`p-4`, `px-3`, `py-[13px]`, …)
 *    and `divide-y`/`divide-x` rows are counted per file. The counts in
 *    `spacing-baseline.json` may only go down. New spacing must use the DS
 *    tokens (`px-ds-md`, `gap-ds-sm`, …) or a DS component. After a
 *    legitimate decrease, refresh the baseline with
 *    `UPDATE_SPACING_BASELINE=1 npx vitest run spacing-guard`.
 */

import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'
import { describe, expect, it } from 'vitest'

const testDir = dirname(fileURLToPath(import.meta.url))
const srcRoot = resolve(testDir, '..')
const coreRoot = resolve(srcRoot, '../..')
const repoRoot = resolve(coreRoot, '..')
const cloudApp = join(repoRoot, 'cloud/app')
const scanRoots = [srcRoot, ...(existsSync(cloudApp) ? [cloudApp] : [])]
const baselinePath = join(testDir, 'spacing-baseline.json')

/**
 * Baseline and allowlist keys: core files keep their `core/...` path in the
 * monorepo and in the export; cloud files use their monorepo path.
 */
function keyOf(file: string): string {
  const abs = resolve(file)
  return abs.startsWith(coreRoot + sep)
    ? `core/${relative(coreRoot, abs).split(sep).join('/')}`
    : relative(repoRoot, abs).split(sep).join('/')
}

/** A padding utility that is not a DS token (variant prefixes allowed). */
const RAW_PADDING = /^(?:[\w-]+:)*p[xytblrse]?-(?!ds-)\S+$/
/** A DS padding utility: `p-ds-md`, `sm:px-ds-lg`, … */
const DS_PADDING = /^(?:[\w-]+:)*p[xytblrse]?-ds-\S+$/
const DIVIDE = /^(?:[\w-]+:)*divide-[xy](?:-\S+)?$/

/**
 * Offending flush sections, keyed `<repo-relative file>#<testId>`.
 * Shrink-only: remove an entry once that section's body children are fixed.
 */
const FLUSH_ALLOWLIST: Record<string, string> = {
  'core/app/src/pages/IdeaDetailPage.tsx#idea-comments-section':
    'comment rows (divide-y); migrate to ds-section-list once IdeaDetailPage is free',
  'core/app/src/components/ActivationChecklist.tsx#activation-checklist':
    'checklist is a gap-px cell grid with its own borders; keep',
}

function listSourceFiles(dir: string): string[] {
  return readdirSync(dir, { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile() && /\.tsx$/.test(entry.name) && !/\.test\./.test(entry.name))
    .filter(
      (entry) =>
        !entry.parentPath
          .split(sep)
          .some((part) => part === '__tests__' || part === 'node_modules'),
    )
    .map((entry) => join(entry.parentPath, entry.name))
}

function parse(file: string): ts.SourceFile {
  return ts.createSourceFile(
    file,
    readFileSync(file, 'utf8'),
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  )
}

function attrsOf(el: ts.JsxOpeningLikeElement): Map<string, ts.JsxAttribute> {
  const out = new Map<string, ts.JsxAttribute>()
  for (const prop of el.attributes.properties) {
    if (ts.isJsxAttribute(prop)) out.set(prop.name.getText(), prop)
  }
  return out
}

/** Every whitespace-separated token of string literals inside a className value. */
function classTokens(attr: ts.JsxAttribute | undefined): string[] {
  if (!attr?.initializer) return []
  const tokens: string[] = []
  const visit = (node: ts.Node) => {
    if (
      ts.isStringLiteral(node) ||
      ts.isNoSubstitutionTemplateLiteral(node) ||
      ts.isTemplateHead(node) ||
      ts.isTemplateMiddle(node) ||
      ts.isTemplateTail(node)
    ) {
      tokens.push(...node.text.split(/\s+/).filter(Boolean))
    }
    ts.forEachChild(node, visit)
  }
  visit(attr.initializer)
  return tokens
}

interface FileIndex {
  sf: ts.SourceFile
  /** Identifier -> initializer or assigned value (`let x; x = <jsx/>`). */
  values: Map<string, ts.Expression[]>
}

function indexValues(sf: ts.SourceFile): FileIndex['values'] {
  const values = new Map<string, ts.Expression[]>()
  const add = (name: string, expr: ts.Expression) => {
    values.set(name, [...(values.get(name) ?? []), expr])
  }
  const visit = (node: ts.Node) => {
    if (ts.isVariableDeclaration(node) && node.initializer && ts.isIdentifier(node.name)) {
      add(node.name.text, node.initializer)
    }
    if (
      ts.isBinaryExpression(node) &&
      node.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
      ts.isIdentifier(node.left)
    ) {
      add(node.left.text, node.right)
    }
    ts.forEachChild(node, visit)
  }
  visit(sf)
  return values
}

/**
 * Top-level JSX reachable from an expression through conditionals, logical
 * operators, parentheses and same-file identifiers. `null` when an identifier
 * cannot be resolved (the caller reports it as unresolved).
 */
function reachableJsx(
  expr: ts.Expression,
  values: FileIndex['values'],
  depth = 0,
): ts.Expression[] | null {
  if (depth > 8) return null
  if (
    expr.kind === ts.SyntaxKind.NullKeyword ||
    (ts.isIdentifier(expr) && expr.text === 'undefined')
  ) {
    return []
  }
  if (ts.isParenthesizedExpression(expr)) return reachableJsx(expr.expression, values, depth + 1)
  if (ts.isJsxElement(expr) || ts.isJsxSelfClosingElement(expr) || ts.isJsxFragment(expr)) {
    return [expr]
  }
  if (ts.isConditionalExpression(expr)) {
    const a = reachableJsx(expr.whenTrue, values, depth + 1)
    const b = reachableJsx(expr.whenFalse, values, depth + 1)
    return a && b ? [...a, ...b] : null
  }
  if (
    ts.isBinaryExpression(expr) &&
    expr.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken
  ) {
    return reachableJsx(expr.right, values, depth + 1)
  }
  if (ts.isBinaryExpression(expr) && expr.operatorToken.kind === ts.SyntaxKind.BarBarToken) {
    return reachableJsx(expr.right, values, depth + 1)
  }
  if (ts.isIdentifier(expr)) {
    const sources = values.get(expr.text)
    if (!sources) return null
    const out: ts.Expression[] = []
    for (const source of sources) {
      const found = reachableJsx(source, values, depth + 1)
      if (!found) return null
      out.push(...found)
    }
    return out
  }
  return null
}

/** Direct body children of a flush section; `null` entries are unresolved expressions. */
function flushChildren(section: ts.JsxElement, index: FileIndex): (ts.Node | null)[] {
  const out: (ts.Node | null)[] = []
  for (const child of section.children) {
    if (ts.isJsxText(child)) continue
    if (ts.isJsxExpression(child)) {
      if (!child.expression) continue
      const found = reachableJsx(child.expression, index.values)
      if (found === null) out.push(null)
      else out.push(...found)
      continue
    }
    out.push(child)
  }
  return out
}

/** A host child passes if it brings DS padding or any DS class; components own their layout. */
function childProblem(node: ts.Node, index: FileIndex): string | null {
  if (ts.isJsxFragment(node)) {
    for (const nested of node.children) {
      if (ts.isJsxText(nested)) continue
      const problem = childProblem(nested, index)
      if (problem) return problem
    }
    return null
  }
  if (ts.isJsxExpression(node)) {
    const found = node.expression ? reachableJsx(node.expression, index.values) : []
    if (found === null) return 'unresolved expression'
    for (const nested of found ?? []) {
      const problem = childProblem(nested, index)
      if (problem) return problem
    }
    return null
  }
  const open = ts.isJsxElement(node)
    ? node.openingElement
    : ts.isJsxSelfClosingElement(node)
      ? node
      : null
  if (!open) return null
  const tag = open.tagName.getText()
  if (/^[A-Z]/.test(tag)) return null // component: owns its own padding
  const tokens = classTokens(attrsOf(open).get('className'))
  if (tokens.some((token) => DS_PADDING.test(token) || token.startsWith('ds-'))) return null
  if (tokens.length === 0 && ts.isJsxElement(node)) {
    // Bare wrapper (no class): it passes when everything it wraps passes.
    for (const child of node.children) {
      if (ts.isJsxText(child)) continue
      const problem = childProblem(child, index)
      if (problem) return problem
    }
    return null
  }
  return `<${tag}${tokens.length ? ` class="${tokens.join(' ')}"` : ''}>`
}

function flushSectionsIn(file: string): { testId: string; problems: string[] }[] {
  const sf = parse(file)
  const index: FileIndex = { sf, values: indexValues(sf) }
  const results: { testId: string; problems: string[] }[] = []
  const visit = (node: ts.Node) => {
    if (ts.isJsxElement(node) && node.openingElement.tagName.getText() === 'Section') {
      const attrs = attrsOf(node.openingElement)
      if (attrs.has('flush')) {
        const testIdAttr = attrs.get('testId')
        const testId = testIdAttr?.initializer
          ? testIdAttr.initializer.getText().replace(/["{}]/g, '')
          : '?'
        const problems: string[] = []
        for (const child of flushChildren(node, index)) {
          if (child === null) {
            problems.push('unresolved expression')
            continue
          }
          const problem = childProblem(child, index)
          if (problem) problems.push(problem)
        }
        results.push({ testId, problems })
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(sf)
  return results
}

/** Padding utilities and divide rows per file, inside className values only. */
function ratchetCount(file: string): number {
  const sf = parse(file)
  let count = 0
  const visit = (node: ts.Node) => {
    if (ts.isJsxAttribute(node) && node.name.getText() === 'className') {
      for (const token of classTokens(node)) {
        if (RAW_PADDING.test(token) || DIVIDE.test(token)) count += 1
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(sf)
  return count
}

describe('spacing guard', () => {
  const files = scanRoots.flatMap(listSourceFiles)

  it('flush sections bring their own DS padding or DS list class (allowlist only shrinks)', () => {
    const problems: string[] = []
    const offending = new Set<string>()
    for (const file of files) {
      for (const section of flushSectionsIn(file)) {
        if (section.problems.length === 0) continue
        const key = `${keyOf(file)}#${section.testId}`
        offending.add(key)
        if (!(key in FLUSH_ALLOWLIST)) {
          problems.push(
            `${key}: flush body child without DS padding or ds-* class: ${section.problems.join(', ')}`,
          )
        }
      }
    }
    for (const key of Object.keys(FLUSH_ALLOWLIST)) {
      if (!offending.has(key)) problems.push(`${key}: allowlisted but fixed; remove the entry`)
    }
    expect(problems).toEqual([])
  })

  it('raw padding and divide-y counts per file do not grow (baseline only shrinks)', () => {
    const current: Record<string, number> = {}
    for (const file of files) {
      const count = ratchetCount(file)
      if (count > 0) current[keyOf(file)] = count
    }

    if (process.env.UPDATE_SPACING_BASELINE) {
      writeFileSync(baselinePath, `${JSON.stringify(current, null, 2)}\n`)
      return
    }

    const baseline = JSON.parse(readFileSync(baselinePath, 'utf8')) as Record<string, number>
    if (!existsSync(cloudApp)) {
      // Export: cloud/ is not there, so its baseline entries cannot be checked.
      for (const key of Object.keys(baseline)) {
        if (key.startsWith('cloud/')) delete baseline[key]
      }
    }
    const problems: string[] = []
    const keys = new Set([...Object.keys(current), ...Object.keys(baseline)])
    for (const key of [...keys].sort()) {
      const now = current[key] ?? 0
      const before = baseline[key] ?? 0
      if (now > before) {
        problems.push(
          `${key}: ${now} raw padding/divide utilities, baseline ${before}; use ds-* spacing tokens or a DS component`,
        )
      } else if (now < before) {
        problems.push(`${key}: ${now} now, baseline ${before}; refresh spacing-baseline.json`)
      }
    }
    expect(problems).toEqual([])
  })
})

/**
 * Visible-literal guard (i18n, H-03).
 *
 * Every user-visible string in core/app/src (pages, components, widget, lib) comes
 * from the i18n dictionaries. This test walks the TSX sources with the TypeScript
 * AST and fails for a literal that a user can read:
 *   - JSX text nodes that contain letters (`<p>Speichern</p>`),
 *   - static string children in braces (`{'Ja'}`, `{ok ? 'Ja' : 'Nein'}`),
 *   - the string props `aria-label`, `title`, `placeholder` and `alt`, whether they
 *     hold a literal, a template (only its static parts count) or a conditional.
 *
 * Not scanned: test files, the dictionaries, the dev-only design reference (its own
 * HTML entry, never in the production build) and the files listed in EXCLUDED_FILES.
 * Technical values (URLs, example e-mail addresses and domains, hex colours) and
 * brand or plan names are allowed (ALLOWED_VALUES, ALLOWED_PATTERNS). Adding an entry
 * there is a reviewed decision.
 *
 * Violations that exist today are none: this guard has no baseline. Fix a new hit by
 * moving the string into the matching dictionary in all six locales
 * (src/lib/i18n/dictionaries), not by adding it to an allowlist.
 */

import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'
import { describe, expect, it } from 'vitest'

const testDir = dirname(fileURLToPath(import.meta.url))
// core/app/src/__tests__ -> core/app/src
const srcRoot = resolve(testDir, '..')

/** Attributes whose value a sighted or screen-reader user perceives. */
const VISIBLE_PROPS = new Set(['aria-label', 'title', 'placeholder', 'alt'])

/** Paths relative to src/, forward slashes. Prefix match for directories. */
const EXCLUDED_PATHS = [
  // Dev-only design reference (design-reference.html, not in the production build).
  'design-reference/',
]

/** Brand names, edition labels and plan tiers: identical in every locale. */
const ALLOWED_VALUES = new Set(['Votepit', 'Cloud', 'Community', 'Pro', 'Lite', 'GitHub', 'MCP'])

/** Technical example values that are not prose. */
const ALLOWED_PATTERNS = [
  /^https?:\/\//i, // URL placeholders such as https://example.com/hooks/votepit
  /^[\w.+-]+@[\w-]+(\.[\w-]+)+$/, // e-mail placeholders such as name@example.com
  /^(www\.)?[\w-]+(\.[\w-]+)*\.[a-z]{2,}$/i, // bare domains such as example.com
  /^[\w.-]+\/[\w.-]+$/, // repository placeholders such as owner/repo
  /^#[0-9a-f]{3,8}$/i, // colour placeholders such as #1fa890
  /^ENG$/, // example issue-tracker team key
]

const HAS_LETTERS = /\p{L}{2,}/u

interface Violation {
  /** Path relative to core/app/src, with forward slashes. */
  file: string
  line: number
  message: string
}

function isExcluded(rel: string): boolean {
  return EXCLUDED_PATHS.some((path) => rel.startsWith(path) || rel.includes(`/${path}`))
}

function isAllowed(value: string): boolean {
  if (ALLOWED_PATTERNS.some((pattern) => pattern.test(value))) return true
  // Brand lockups such as "Votepit · Cloud" consist of allowed words and separators only.
  const words = value.split(/[\s·|•–—/&,]+/).filter((word) => word !== '')
  return words.length > 0 && words.every((word) => ALLOWED_VALUES.has(word))
}

/** A static piece of text a user could read (letters, not a technical value). */
function isVisibleText(value: string): boolean {
  const text = value.trim()
  return HAS_LETTERS.test(text) && !isAllowed(text)
}

/**
 * Static text pieces an expression can produce. Template placeholders are dropped,
 * so `${t('key')}: ${name}` yields only ": "; runtime-only values yield nothing.
 */
function staticTexts(expr: ts.Expression | undefined): string[] {
  if (!expr) return []
  if (ts.isStringLiteral(expr) || ts.isNoSubstitutionTemplateLiteral(expr)) return [expr.text]
  if (ts.isTemplateExpression(expr)) {
    return [expr.head.text, ...expr.templateSpans.map((span) => span.literal.text)]
  }
  if (ts.isParenthesizedExpression(expr)) return staticTexts(expr.expression)
  if (ts.isConditionalExpression(expr)) {
    return [...staticTexts(expr.whenTrue), ...staticTexts(expr.whenFalse)]
  }
  if (
    ts.isBinaryExpression(expr) &&
    (expr.operatorToken.kind === ts.SyntaxKind.BarBarToken ||
      expr.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken)
  ) {
    return [...staticTexts(expr.left), ...staticTexts(expr.right)]
  }
  return []
}

/** All visible-literal violations in one source file. */
function scanSource(file: string, text: string): Violation[] {
  const sourceFile = ts.createSourceFile(
    file,
    text,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  )
  const found: Violation[] = []
  const report = (node: ts.Node, what: string, value: string) => {
    const line = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line + 1
    found.push({ file, line, message: `${what} "${value.trim()}"` })
  }

  const visit = (node: ts.Node): void => {
    if (ts.isJsxText(node)) {
      if (isVisibleText(node.text)) report(node, 'JSX text', node.text)
    } else if (ts.isJsxExpression(node) && node.expression) {
      // Only braces used as children; attribute values are handled below.
      const parent = node.parent
      if (ts.isJsxElement(parent) || ts.isJsxFragment(parent)) {
        for (const value of staticTexts(node.expression)) {
          if (isVisibleText(value)) report(node, 'JSX child', value)
        }
      }
    } else if (ts.isJsxAttribute(node) && VISIBLE_PROPS.has(node.name.getText(sourceFile))) {
      const init = node.initializer
      const values = !init
        ? []
        : ts.isJsxExpression(init)
          ? staticTexts(init.expression)
          : ts.isStringLiteral(init)
            ? [init.text]
            : []
      for (const value of values) {
        if (isVisibleText(value)) report(node, `${node.name.getText(sourceFile)}=`, value)
      }
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
    if (entry.isDirectory()) {
      if (entry.name !== '__tests__') out.push(...listSourceFiles(path))
    } else if (entry.name.endsWith('.tsx') && !entry.name.endsWith('.test.tsx')) {
      out.push(path)
    }
  }
  return out.sort()
}

function scanAll(): { fileCount: number; violations: Violation[] } {
  const files = listSourceFiles(srcRoot)
    .map((path) => ({ path, rel: relative(srcRoot, path).split(sep).join('/') }))
    .filter(({ rel }) => !isExcluded(rel))
  const violations = files.flatMap(({ path, rel }) => scanSource(rel, readFileSync(path, 'utf8')))
  return { fileCount: files.length, violations }
}

describe('visible-literal scanner', () => {
  it('flags JSX text and static string children', () => {
    const found = scanSource(
      'x.tsx',
      'const a = <p>Speichern</p>\nconst b = <b>{ok ? "Ja" : "Nein"}</b>',
    )
    expect(found.map((v) => v.message)).toEqual([
      'JSX text "Speichern"',
      'JSX child "Ja"',
      'JSX child "Nein"',
    ])
  })

  it('flags the visible props when they hold static prose, including template prefixes', () => {
    const source = `
      const a = <button aria-label="Schließen" />
      const b = <input placeholder={ready ? 'Titel suchen' : 'Titel'} />
      const c = <img alt={\`Logo von \${name}\`} />
      const d = <a title={\`\${t('key')}: \${name}\`} />
    `
    expect(scanSource('x.tsx', source).map((v) => v.message)).toEqual([
      'aria-label= "Schließen"',
      'placeholder= "Titel suchen"',
      'placeholder= "Titel"',
      'alt= "Logo von"',
    ])
  })

  it('ignores runtime values, translated keys, technical attributes and allowed values', () => {
    const source = `
      const a = <p>{t('key')} {count}</p>
      const b = <span title={label}>{name}</span>
      const c = <input placeholder="name@example.com" />
      const d = <input placeholder="https://example.com/hooks/votepit" />
      const e = <span className="flex gap-2" data-testid="board-row">Votepit</span>
      const f = <input placeholder="#1fa890" />
      const g = <span title="Pro" />
    `
    expect(scanSource('x.tsx', source)).toEqual([])
  })
})

describe('visible-literal guard', () => {
  it('finds no hard-coded visible literal in core/app/src (widget included)', () => {
    const { fileCount, violations } = scanAll()
    expect(fileCount).toBeGreaterThan(100)
    expect(listSourceFiles(srcRoot).some((p) => p.endsWith(join('widget', 'WidgetApp.tsx')))).toBe(
      true,
    )
    expect(violations.map((v) => `${v.file}:${v.line} ${v.message}`)).toEqual([])
  })
})

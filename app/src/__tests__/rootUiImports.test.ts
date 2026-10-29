/**
 * Root `@votepit/ui` import guard (design-system residue, see
 * the design-system planning ledger, G-03).
 *
 * The app imports components from `@votepit/ui/ds`. The root entry still holds
 * legacy primitives that have no design-system equivalent with the same
 * behaviour and states. Every file that imports from the root entry must be
 * listed in `ALLOWED` with the imported names and a one-line reason. The guard
 * fails for a new root import, for a changed set of names, and for an entry
 * that no longer imports from the root (so the list can only shrink).
 */

import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'
import { describe, expect, it } from 'vitest'

const testDir = dirname(fileURLToPath(import.meta.url))
const srcRoot = resolve(testDir, '..')
const ROOT_SPECIFIER = '@votepit/ui'

interface AllowedImport {
  /** Imported names, sorted; type-only imports are listed as written. */
  names: string[]
  reason: string
}

/** Keys: paths relative to core/app/src, forward slashes. */
const ALLOWED: Record<string, AllowedImport> = {
  'components/AdminShell.tsx': {
    names: ['AppShell', 'SidebarNav', 'SidebarNavGroup', 'SidebarNavItem'],
    reason: 'admin frame with sidebar; no design-system app shell exists',
  },
  'components/AuthShell.tsx': {
    names: ['LegalFooter', 'VotepitLogo'],
    reason:
      'auth split panel: the logo link needs edition, size lg and an aria label; ds BrandMark has none of these',
  },
  'components/BrandingPreview.tsx': {
    names: ['VotepitMark'],
    reason: 'mark-only logo in the branding preview; the ds has only the full wordmark (BrandMark)',
  },
  'components/LocalizedHeader.tsx': {
    names: ['Header'],
    reason: 'app bar with branding, scope, account menu and nav; ds TopBar has none of these slots',
  },
  'components/NotFoundState.tsx': {
    names: ['VotepitLogo'],
    reason:
      'wordmark link with edition label and test id; ds BrandMark is not a link and has no test id',
  },
  'components/SecuritySettings.tsx': {
    names: ['DescriptionList'],
    reason: 'key/value rows of the settings sheet; no design-system equivalent',
  },
  'pages/BoardPage.tsx': {
    names: ['Celebration', 'SortTabs', 'SortValue'],
    reason:
      'board sort tabs use the accent tone (ds Tabs is ink only); Celebration has no ds equivalent',
  },
  'pages/DiscoverPage.tsx': {
    names: ['Pagination'],
    reason: 'pagination has no design-system equivalent (single caller)',
  },
  'pages/RoadmapPage.tsx': {
    names: ['Status', 'statusDotClass'],
    reason: 'status dot colours; ds StatusChip is tone based and would change the roadmap look',
  },
  'pages/SignupAccountPage.tsx': {
    names: ['Fieldset'],
    reason:
      'legend group with no ds equivalent; file is owned by another agent (W1), migrate later',
  },
  '__tests__/Celebration.test.tsx': {
    names: ['Celebration'],
    reason: 'test of the root Celebration that the app uses',
  },
  '__tests__/SortTabs.test.tsx': {
    names: ['SortTabs'],
    reason: 'test of the root SortTabs that the app uses',
  },
  'pages/BoardPage.topbar.test.tsx': {
    names: ['Header'],
    reason: 'test renders the root Header that LocalizedHeader wraps',
  },
}

function listSourceFiles(dir: string): string[] {
  return readdirSync(dir, { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile() && /\.(ts|tsx)$/.test(entry.name))
    .map((entry) => join(entry.parentPath, entry.name))
}

/** Names imported from the root entry in one file (empty when it has none). */
function rootImportNames(file: string): string[] {
  const sourceFile = ts.createSourceFile(
    file,
    readFileSync(file, 'utf8'),
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  )
  const names: string[] = []
  for (const statement of sourceFile.statements) {
    if (!ts.isImportDeclaration(statement)) continue
    if (!ts.isStringLiteral(statement.moduleSpecifier)) continue
    if (statement.moduleSpecifier.text !== ROOT_SPECIFIER) continue
    const clause = statement.importClause
    if (!clause) continue
    if (clause.name) names.push(clause.name.text)
    const bindings = clause.namedBindings
    if (bindings && ts.isNamedImports(bindings)) {
      for (const element of bindings.elements) names.push(element.name.text)
    }
  }
  return names.sort()
}

function findRootImports(): Map<string, string[]> {
  const found = new Map<string, string[]>()
  for (const file of listSourceFiles(srcRoot)) {
    const names = rootImportNames(file)
    if (names.length === 0) continue
    const key = relative(srcRoot, file).split(sep).join('/')
    found.set(key, names)
  }
  return found
}

describe('root @votepit/ui imports', () => {
  const found = findRootImports()

  it('every root import in core/app/src is allowlisted with the same names', () => {
    const problems: string[] = []
    for (const [file, names] of found) {
      const allowed = ALLOWED[file]
      if (!allowed) {
        problems.push(
          `${file}: imports ${names.join(', ')} from the root entry; use @votepit/ui/ds`,
        )
        continue
      }
      const expected = [...allowed.names].sort()
      if (expected.join(',') !== names.join(',')) {
        problems.push(
          `${file}: root names are ${names.join(', ')}, allowlist has ${expected.join(', ')}`,
        )
      }
    }
    expect(problems).toEqual([])
  })

  it('every allowlist entry still imports from the root entry (list can only shrink)', () => {
    const stale = Object.keys(ALLOWED).filter((file) => !found.has(file))
    expect(stale).toEqual([])
  })
})

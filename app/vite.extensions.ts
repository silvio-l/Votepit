import path from 'node:path'
import { searchForWorkspaceRoot } from 'vite'

/**
 * Shared by vite.config.ts and vitest.config.ts: where the
 * `@votepit/app-extensions` module comes from.
 *
 * Default: `src/extensions/default.ts` (Community build, empty registry).
 * `VOTEPIT_APP_EXTENSIONS=<path to a module>` swaps in a downstream
 * registry that may live outside this package. Such a module still has to
 * share this package's single React/UI-kit instance, hence `dedupe`.
 */

const ALIAS = '@votepit/app-extensions'

export function extensionsEntry(packageDir: string): string {
  const fromEnv = process.env.VOTEPIT_APP_EXTENSIONS
  return fromEnv !== undefined && fromEnv !== ''
    ? path.resolve(fromEnv)
    : path.resolve(packageDir, 'src/extensions/default.ts')
}

/** Directory of an external extension module, or null for the built-in default. */
export function extensionsDir(): string | null {
  const fromEnv = process.env.VOTEPIT_APP_EXTENSIONS
  return fromEnv !== undefined && fromEnv !== '' ? path.dirname(path.resolve(fromEnv)) : null
}

export function appExtensionsResolve(packageDir: string) {
  return {
    alias: { [ALIAS]: extensionsEntry(packageDir) },
    dedupe: [
      'react',
      'react-dom',
      'react-router-dom',
      '@votepit/ui',
      'lucide-react',
      '@testing-library/react',
      '@testing-library/user-event',
      '@testing-library/jest-dom',
    ],
  }
}

/**
 * Vite only serves files under the workspace root by default; an external
 * extension directory has to be allowed explicitly (dev server + Vitest).
 * The workspace root stays allowed so @votepit/ui (../packages/ui) keeps
 * resolving.
 */
export function extensionsFsAllow(packageDir: string): string[] {
  const root = searchForWorkspaceRoot(packageDir)
  const dir = extensionsDir()
  return dir === null ? [root] : [root, dir]
}

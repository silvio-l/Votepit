import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { boardCacheKeys, boardCacheLookupKeys } from './theme'

const SCRIPT = readFileSync(join(process.cwd(), 'public', 'theme-init.js'), 'utf8')

interface Storage {
  getItem(key: string): string | null
}

/** Runs the real pre-paint script against a stubbed document and returns the data-theme it sets. */
function preparedTheme(pathname: string, storage: Storage | null): string | undefined {
  const dataset: Record<string, string> = {}
  const documentElement = {
    setAttribute: (name: string, value: string) => {
      if (name === 'data-theme') dataset.theme = value
    },
  }
  const windowStub = {
    location: { pathname },
    localStorage: storage,
  }
  // The script only touches window, document and its own locals.
  new Function('window', 'document', SCRIPT)(windowStub, { documentElement })
  return dataset.theme
}

function storage(entries: Record<string, string | null>): Storage {
  return {
    getItem: (key) => (key in entries ? (entries[key] ?? null) : null),
  }
}

describe('public/theme-init.js (pre-paint scheme)', () => {
  it('applies a cached board mode over the viewer choice on a cloud board page', () => {
    const s = storage({
      vp_board_theme: JSON.stringify({ '/acc/board': 'dark' }),
      vp_theme: 'light',
    })
    expect(preparedTheme('/acc/board/idea/3', s)).toBe('dark')
    expect(preparedTheme('/acc/board', s)).toBe('dark')
  })

  it('applies a cached board mode on a self-hosted board subpage', () => {
    const s = storage({ vp_board_theme: JSON.stringify({ '/board': 'light' }), vp_theme: 'dark' })
    expect(preparedTheme('/board/roadmap', s)).toBe('light')
  })

  it('applies the root entry of the default board at its root URL only', () => {
    const s = storage({
      vp_board_theme: JSON.stringify({ '<root>/acc': 'light' }),
      vp_theme: 'dark',
    })
    expect(preparedTheme('/acc', s)).toBe('light')
    // Admin pages of the same account must not inherit the default board's mode.
    expect(preparedTheme('/acc/admin/boards', s)).toBe('dark')
  })

  it('falls back to the viewer choice when the board has no entry or chose System', () => {
    const s = storage({ vp_board_theme: JSON.stringify({ '/other': 'dark' }), vp_theme: 'light' })
    expect(preparedTheme('/acc/board', s)).toBe('light')
    expect(preparedTheme('/acc/board', storage({ vp_theme: 'system' }))).toBeUndefined()
  })

  it('sets nothing for System everywhere (the DS media query decides)', () => {
    expect(preparedTheme('/acc/board', storage({}))).toBeUndefined()
  })

  it('survives blocked storage and corrupt cache entries', () => {
    expect(preparedTheme('/acc/board', null)).toBeUndefined()
    expect(
      preparedTheme('/acc/board', storage({ vp_board_theme: '{not json', vp_theme: 'dark' })),
    ).toBe('dark')
    expect(preparedTheme('/acc/board', storage({ vp_board_theme: '[]', vp_theme: 'dark' }))).toBe(
      'dark',
    )
    expect(
      preparedTheme(
        '/acc/board',
        storage({ vp_board_theme: JSON.stringify({ '/acc/board': 'plum' }) }),
      ),
    ).toBeUndefined()
  })
})

describe('board cache keys (write side and pre-paint lookup agree)', () => {
  const cases: Array<{ base: string; root: string; path: string }> = [
    { base: '/acc/board', root: '/acc', path: '/acc/board' },
    { base: '/acc/board', root: '/acc', path: '/acc/board/idea/12/edit' },
    { base: '/acc/board', root: '/acc', path: '/acc/board/roadmap' },
    { base: '/acc/board', root: '/acc', path: '/acc/board/submit' },
    { base: '/board', root: '/', path: '/board' },
    { base: '/board', root: '/', path: '/board/roadmap' },
    { base: '/board', root: '/', path: '/' },
  ]

  for (const { base, root, path } of cases) {
    it(`finds the entry written for ${base} when visiting ${path}`, () => {
      const written = boardCacheKeys(base, path, root)
      const lookup = boardCacheLookupKeys(path)
      expect(written.some((key) => lookup.includes(key))).toBe(true)
    })
  }

  it('stores the root entry only for the default board at its root URL', () => {
    expect(boardCacheKeys('/acc/board', '/acc/board', '/acc')).toEqual(['/acc/board'])
    expect(boardCacheKeys('/acc/board', '/acc', '/acc')).toEqual(['/acc/board', '<root>/acc'])
  })
})

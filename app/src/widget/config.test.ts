import { describe, expect, it } from 'vitest'
import { acceptHostMessage, parseWidgetConfig } from './config'

const meta = JSON.stringify({
  origins: ['https://shop.example.com', 'https://www.example.com:8443'],
  board: { slug: 'ideas', name: 'Ideas' },
})

describe('parseWidgetConfig', () => {
  it('reads origins, board and the base path', () => {
    expect(parseWidgetConfig(meta, '/acme/ideas/widget')).toEqual({
      origins: ['https://shop.example.com', 'https://www.example.com:8443'],
      board: { slug: 'ideas', name: 'Ideas', themeMode: 'system' },
      basePath: '/acme/ideas',
      theme: null,
    })
  })

  it('reads the board display mode and treats anything unknown as system', () => {
    const withMode = (theme_mode: unknown) =>
      parseWidgetConfig(
        JSON.stringify({
          origins: ['https://shop.example.com'],
          board: { slug: 'ideas', name: 'Ideas', theme_mode },
        }),
        '/acme/ideas/widget',
      )?.board.themeMode
    expect(withMode('dark')).toBe('dark')
    expect(withMode('light')).toBe('light')
    expect(withMode('system')).toBe('system')
    expect(withMode('plum')).toBe('system')
    expect(withMode(undefined)).toBe('system')
  })

  it('keeps only well-formed hex colours from the theme', () => {
    const themed = JSON.stringify({
      origins: ['https://shop.example.com'],
      board: { slug: 'ideas', name: 'Ideas' },
      theme: { primary_color: '#1d4ed8', secondary_color: 'red; } body{' },
    })
    expect(parseWidgetConfig(themed, '/acme/ideas/widget')?.theme).toEqual({
      primary_color: '#1d4ed8',
    })
  })

  it('ignores a theme without any valid colour', () => {
    const themed = JSON.stringify({
      origins: ['https://shop.example.com'],
      board: { slug: 'ideas', name: 'Ideas' },
      theme: { primary_color: 'url(x)', secondary_color: '#44556677' },
    })
    expect(parseWidgetConfig(themed, '/acme/ideas/widget')?.theme).toBeNull()
  })

  it.each([
    [null, '/ideas/widget'],
    ['not json', '/ideas/widget'],
    [JSON.stringify({ origins: [], board: { slug: 'a', name: 'b' } }), '/ideas/widget'],
    [JSON.stringify({ origins: ['*'], board: { slug: 'a', name: 'b' } }), '/ideas/widget'],
    [
      JSON.stringify({ origins: ['http://x.example'], board: { slug: 'a', name: 'b' } }),
      '/ideas/widget',
    ],
    [meta, '/ideas'],
  ])('rejects malformed config %#', (content, path) => {
    expect(parseWidgetConfig(content, path)).toBeNull()
  })
})

describe('acceptHostMessage', () => {
  const parent = {}
  const origins = ['https://shop.example.com']

  it('accepts identify from the parent on an allowed origin', () => {
    expect(
      acceptHostMessage(
        {
          origin: 'https://shop.example.com',
          source: parent as MessageEventSource,
          data: { type: 'votepit:identify', jwt: 'a.b.c' },
        },
        parent,
        origins,
      ),
    ).toEqual({ type: 'votepit:identify', jwt: 'a.b.c' })
  })

  it('ignores a foreign origin', () => {
    expect(
      acceptHostMessage(
        {
          origin: 'https://evil.example',
          source: parent as MessageEventSource,
          data: { type: 'votepit:identify', jwt: 'a.b.c' },
        },
        parent,
        origins,
      ),
    ).toBeNull()
  })

  it('ignores a window other than the parent (e.g. a nested frame)', () => {
    expect(
      acceptHostMessage(
        {
          origin: 'https://shop.example.com',
          source: {} as MessageEventSource,
          data: { type: 'votepit:logout' },
        },
        parent,
        origins,
      ),
    ).toBeNull()
  })

  it('ignores unknown shapes', () => {
    for (const data of [null, 'votepit:logout', { type: 'votepit:identify' }, { type: 'other' }]) {
      expect(
        acceptHostMessage(
          { origin: 'https://shop.example.com', source: parent as MessageEventSource, data },
          parent,
          origins,
        ),
      ).toBeNull()
    }
  })
})

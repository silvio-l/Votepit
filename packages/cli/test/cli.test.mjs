import { describe, expect, it, vi } from 'vitest'
import { run } from '../src/cli.mjs'

const BASE_URL = 'https://feedback.example.com'
const TOKEN = 'tok_abc123'

function jsonResponse(body, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function makeIo() {
  const out = []
  const err = []
  return {
    stdout: (s) => out.push(s),
    stderr: (s) => err.push(s),
    out: () => out.join(''),
    err: () => err.join(''),
  }
}

describe('run — global config resolution', () => {
  it('fails fast without calling fetch when --url/--token are missing', async () => {
    const fetchMock = vi.fn()
    const io = makeIo()

    const code = await run(['board'], { fetch: fetchMock, env: {}, ...io })

    expect(code).not.toBe(0)
    expect(fetchMock).not.toHaveBeenCalled()
    expect(io.err()).toMatch(/--url/)
    expect(io.err()).toMatch(/--token/)
  })

  it('reads config from environment variables when no flags are given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ id: 1, name: 'Demo' }))
    const io = makeIo()

    const code = await run(['board'], {
      fetch: fetchMock,
      env: { VOTEPIT_URL: BASE_URL, VOTEPIT_TOKEN: TOKEN },
      ...io,
    })

    expect(code).toBe(0)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [calledUrl] = fetchMock.mock.calls[0]
    expect(String(calledUrl)).toBe(`${BASE_URL}/api/v1/board`)
  })

  it('prefers a --url/--token flag over the environment variable', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ id: 1, name: 'Demo' }))
    const io = makeIo()

    await run(['board', '--url', BASE_URL, '--token', TOKEN], {
      fetch: fetchMock,
      env: { VOTEPIT_URL: 'https://other.example.com', VOTEPIT_TOKEN: 'other-token' },
      ...io,
    })

    const [calledUrl, calledInit] = fetchMock.mock.calls[0]
    expect(String(calledUrl)).toBe(`${BASE_URL}/api/v1/board`)
    expect(calledInit.headers.Authorization).toBe(`Bearer ${TOKEN}`)
  })
})

describe('run — board', () => {
  it('sends GET /api/v1/board with the bearer token and prints the JSON result', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ id: 1, name: 'Demo' }))
    const io = makeIo()

    const code = await run(['board', '--url', BASE_URL, '--token', TOKEN], {
      fetch: fetchMock,
      env: {},
      ...io,
    })

    expect(code).toBe(0)
    const [calledUrl, calledInit] = fetchMock.mock.calls[0]
    expect(calledInit.method).toBe('GET')
    expect(calledInit.headers.Authorization).toBe(`Bearer ${TOKEN}`)
    expect(String(calledUrl)).toBe(`${BASE_URL}/api/v1/board`)
    expect(io.out()).toContain('"name": "Demo"')
  })

  it('adds ?board=<slug> when --board is given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ id: 1 }))
    const io = makeIo()

    await run(['board', '--url', BASE_URL, '--token', TOKEN, '--board', 'roadmap'], {
      fetch: fetchMock,
      env: {},
      ...io,
    })

    const [calledUrl] = fetchMock.mock.calls[0]
    expect(String(calledUrl)).toBe(`${BASE_URL}/api/v1/board?board=roadmap`)
  })

  it('adds ?board=<slug> from VOTEPIT_BOARD when --board is not given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ id: 1 }))
    const io = makeIo()

    await run(['board', '--url', BASE_URL, '--token', TOKEN], {
      fetch: fetchMock,
      env: { VOTEPIT_BOARD: 'roadmap' },
      ...io,
    })

    const [calledUrl] = fetchMock.mock.calls[0]
    expect(String(calledUrl)).toBe(`${BASE_URL}/api/v1/board?board=roadmap`)
  })
})

describe('run — ideas list', () => {
  it('sends GET /api/v1/ideas with status/sort/page as query params', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ideas: [] }))
    const io = makeIo()

    await run(
      [
        'ideas',
        'list',
        '--status',
        'open',
        '--sort',
        'top',
        '--page',
        '2',
        '--url',
        BASE_URL,
        '--token',
        TOKEN,
      ],
      { fetch: fetchMock, env: {}, ...io },
    )

    const [calledUrl, calledInit] = fetchMock.mock.calls[0]
    const parsed = new URL(String(calledUrl))
    expect(parsed.pathname).toBe('/api/v1/ideas')
    expect(parsed.searchParams.get('status')).toBe('open')
    expect(parsed.searchParams.get('sort')).toBe('top')
    expect(parsed.searchParams.get('page')).toBe('2')
    expect(calledInit.method).toBe('GET')
  })

  it('omits query params that were not given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ideas: [] }))
    const io = makeIo()

    await run(['ideas', 'list', '--url', BASE_URL, '--token', TOKEN], { fetch: fetchMock, env: {}, ...io })

    const [calledUrl] = fetchMock.mock.calls[0]
    const parsed = new URL(String(calledUrl))
    expect(parsed.searchParams.has('status')).toBe(false)
    expect(parsed.searchParams.has('sort')).toBe(false)
    expect(parsed.searchParams.has('page')).toBe(false)
  })
})

describe('run — ideas get', () => {
  it('sends GET /api/v1/ideas/{id}', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ id: 42, title: 'Dark mode' }))
    const io = makeIo()

    const code = await run(['ideas', 'get', '42', '--url', BASE_URL, '--token', TOKEN], {
      fetch: fetchMock,
      env: {},
      ...io,
    })

    expect(code).toBe(0)
    const [calledUrl] = fetchMock.mock.calls[0]
    expect(new URL(String(calledUrl)).pathname).toBe('/api/v1/ideas/42')
  })
})

describe('run — ideas create', () => {
  it('sends POST /api/v1/ideas with a JSON body and Content-Type header', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true, id: 9 }))
    const io = makeIo()

    const code = await run(
      [
        'ideas',
        'create',
        '--title',
        'Dark mode',
        '--body',
        'Please add it',
        '--url',
        BASE_URL,
        '--token',
        TOKEN,
      ],
      { fetch: fetchMock, env: {}, ...io },
    )

    expect(code).toBe(0)
    const [calledUrl, calledInit] = fetchMock.mock.calls[0]
    expect(calledInit.method).toBe('POST')
    expect(calledInit.headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(calledInit.body)).toEqual({ title: 'Dark mode', body: 'Please add it' })
    expect(new URL(String(calledUrl)).pathname).toBe('/api/v1/ideas')
  })

  it('fails fast without calling fetch when --title or --body is missing', async () => {
    const fetchMock = vi.fn()
    const io = makeIo()

    const code = await run(['ideas', 'create', '--title', 'Dark mode', '--url', BASE_URL, '--token', TOKEN], {
      fetch: fetchMock,
      env: {},
      ...io,
    })

    expect(code).not.toBe(0)
    expect(fetchMock).not.toHaveBeenCalled()
    expect(io.err()).toMatch(/--body/)
  })
})

describe('run — error passthrough', () => {
  it('prints the server error message to stderr and returns a non-zero exit code', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        jsonResponse({ error: { key: 'insufficient_scope', message: 'Read-only token.' } }, 403),
      )
    const io = makeIo()

    const code = await run(
      ['ideas', 'create', '--title', 'x', '--body', 'y', '--url', BASE_URL, '--token', TOKEN],
      {
        fetch: fetchMock,
        env: {},
        ...io,
      },
    )

    expect(code).not.toBe(0)
    expect(io.err()).toContain('Read-only token.')
    expect(io.out()).toBe('')
  })

  it('falls back to the HTTP status when the body has no error message', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('', { status: 500 }))
    const io = makeIo()

    const code = await run(['board', '--url', BASE_URL, '--token', TOKEN], {
      fetch: fetchMock,
      env: {},
      ...io,
    })

    expect(code).not.toBe(0)
    expect(io.err()).toMatch(/500/)
  })
})

describe('run — usage', () => {
  it('prints usage with no arguments', async () => {
    const io = makeIo()
    await run([], { fetch: vi.fn(), env: {}, ...io })
    expect(io.out() + io.err()).toMatch(/Usage:/)
  })

  it('prints usage for --help', async () => {
    const io = makeIo()
    const code = await run(['--help'], { fetch: vi.fn(), env: {}, ...io })
    expect(code).toBe(0)
    expect(io.out()).toMatch(/Usage:/)
  })

  it('prints usage for -h', async () => {
    const io = makeIo()
    const code = await run(['-h'], { fetch: vi.fn(), env: {}, ...io })
    expect(code).toBe(0)
    expect(io.out()).toMatch(/Usage:/)
  })
})

/**
 * @votepit/cli — a thin, dependency-free client for Votepit's Agent API
 * (`/api/v1/*`), the same 4 operations the MCP server (`POST /api/v1/mcp`)
 * wraps. See ../../../documentation/api-reference.md for the authoritative
 * contract; this file mirrors it exactly rather than inventing its own
 * request/error shapes.
 *
 * `run(argv, io)` is the whole CLI, factored out so it's testable without
 * spawning a subprocess — bin/votepit.mjs just calls it with
 * process.argv.slice(2) and exits with the returned code.
 */

import { parseArgs } from 'node:util'

export const USAGE = `Usage: votepit <command> [options]

Commands:
  board                             Get the resolved board
  ideas list [options]              List ideas on the resolved board
      --status <status>               Filter by status
      --sort <sort>                    Sort order
      --page <n>                       Page number (>= 1)
  ideas get <id>                    Get a single idea by numeric id
  ideas create --title <t> --body <b>
                                     Create an idea (requires a write-scoped token)

Global options (a flag always wins over its environment variable):
  --url <url>       VOTEPIT_URL     Base URL of your Votepit install
  --token <token>   VOTEPIT_TOKEN   Bearer token
  --board <slug>    VOTEPIT_BOARD   Board slug (only needed for a multi-board token)
  -h, --help                        Show this help
`

const GLOBAL_OPTIONS = {
  url: { type: 'string' },
  token: { type: 'string' },
  board: { type: 'string' },
  help: { type: 'boolean', short: 'h' },
}

function resolveConfig(values, env) {
  return {
    url: values.url ?? env.VOTEPIT_URL ?? null,
    token: values.token ?? env.VOTEPIT_TOKEN ?? null,
    board: values.board ?? env.VOTEPIT_BOARD ?? null,
  }
}

function extractErrorMessage(json, status) {
  if (json && typeof json === 'object') {
    const error = json.error
    if (error && typeof error === 'object' && typeof error.message === 'string') {
      return error.message
    }
    if (typeof json.message === 'string') return json.message
  }
  return `Request failed with status ${status}`
}

async function apiRequest(fetchImpl, config, method, path, { query, body } = {}) {
  const url = new URL(path, config.url)
  if (config.board !== null) url.searchParams.set('board', config.board)
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined) url.searchParams.set(key, String(value))
    }
  }

  const headers = { Authorization: `Bearer ${config.token}` }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let response
  try {
    response = await fetchImpl(url.toString(), {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch (err) {
    return { ok: false, message: err instanceof Error ? err.message : String(err) }
  }

  const text = await response.text()
  let json = null
  if (text.length > 0) {
    try {
      json = JSON.parse(text)
    } catch {
      json = null
    }
  }

  if (!response.ok) {
    return { ok: false, message: extractErrorMessage(json, response.status) }
  }
  return { ok: true, data: json }
}

function parseCommandArgs(rest, extraOptions, stderr) {
  try {
    return parseArgs({ args: rest, options: { ...GLOBAL_OPTIONS, ...extraOptions }, allowPositionals: true })
  } catch (err) {
    stderr(`${err instanceof Error ? err.message : String(err)}\n`)
    return null
  }
}

/**
 * Runs the CLI. Returns the process exit code — callers own actually
 * exiting (or, in tests, just asserting on the returned code + captured
 * stdout/stderr).
 */
export async function run(argv, io = {}) {
  const {
    fetch: fetchImpl = globalThis.fetch,
    env = process.env,
    stdout = (s) => process.stdout.write(s),
    stderr = (s) => process.stderr.write(s),
  } = io

  if (argv.length === 0) {
    stdout(USAGE)
    return 1
  }

  const [command, ...rest] = argv

  if (command === '-h' || command === '--help') {
    stdout(USAGE)
    return 0
  }

  let parsed
  let subcommand = null

  if (command === 'board') {
    parsed = parseCommandArgs(rest, {}, stderr)
  } else if (command === 'ideas') {
    subcommand = rest[0]
    const ideasRest = rest.slice(1)
    if (subcommand === 'list') {
      parsed = parseCommandArgs(
        ideasRest,
        { status: { type: 'string' }, sort: { type: 'string' }, page: { type: 'string' } },
        stderr,
      )
    } else if (subcommand === 'get') {
      parsed = parseCommandArgs(ideasRest, {}, stderr)
    } else if (subcommand === 'create') {
      parsed = parseCommandArgs(ideasRest, { title: { type: 'string' }, body: { type: 'string' } }, stderr)
    } else {
      stderr(`Unknown "ideas" subcommand: ${subcommand ?? '(none)'}\n\n`)
      stderr(USAGE)
      return 1
    }
  } else {
    stderr(`Unknown command: ${command}\n\n`)
    stderr(USAGE)
    return 1
  }

  if (parsed === null) return 1
  if (parsed.values.help) {
    stdout(USAGE)
    return 0
  }

  const config = resolveConfig(parsed.values, env)
  const missing = []
  if (config.url === null) missing.push('--url/VOTEPIT_URL')
  if (config.token === null) missing.push('--token/VOTEPIT_TOKEN')
  if (missing.length > 0) {
    stderr(`Missing required configuration: ${missing.join(', ')} must be set.\n`)
    return 1
  }

  let result
  if (command === 'board') {
    result = await apiRequest(fetchImpl, config, 'GET', '/api/v1/board')
  } else if (subcommand === 'list') {
    result = await apiRequest(fetchImpl, config, 'GET', '/api/v1/ideas', {
      query: { status: parsed.values.status, sort: parsed.values.sort, page: parsed.values.page },
    })
  } else if (subcommand === 'get') {
    const id = parsed.positionals[0]
    if (id === undefined) {
      stderr('Missing required argument: <id>\n')
      return 1
    }
    result = await apiRequest(fetchImpl, config, 'GET', `/api/v1/ideas/${id}`)
  } else if (subcommand === 'create') {
    const missingFields = []
    if (parsed.values.title === undefined) missingFields.push('--title')
    if (parsed.values.body === undefined) missingFields.push('--body')
    if (missingFields.length > 0) {
      stderr(`Missing required options: ${missingFields.join(', ')}\n`)
      return 1
    }
    result = await apiRequest(fetchImpl, config, 'POST', '/api/v1/ideas', {
      body: { title: parsed.values.title, body: parsed.values.body },
    })
  }

  if (!result.ok) {
    stderr(`${result.message}\n`)
    return 1
  }

  stdout(`${JSON.stringify(result.data, null, 2)}\n`)
  return 0
}

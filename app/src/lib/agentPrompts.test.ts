import { describe, expect, it } from 'vitest'
import { buildAgentPrompt } from './agentPrompts'

const ORIGIN = 'https://feedback.example.com'
const TOKEN = 'tok_abcdef123456' // gitleaks:allow — test fixture, not a real secret

describe('buildAgentPrompt — claude-code', () => {
  it('includes the claude mcp add command with the real origin and token', () => {
    const prompt = buildAgentPrompt('claude-code', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).toContain('claude mcp add --transport http votepit')
    expect(prompt).toContain(`${ORIGIN}/api/v1/mcp`)
    expect(prompt).toContain(`Authorization: Bearer ${TOKEN}`)
    expect(prompt).toContain('claude mcp list')
  })

  it('adds a ?board= query param and a board note for a single-board token', () => {
    const prompt = buildAgentPrompt('claude-code', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: 'roadmap',
    })
    expect(prompt).toContain(`${ORIGIN}/api/v1/mcp?board=roadmap`)
    expect(prompt).toContain('"roadmap" board')
  })

  it('omits the board query param for a multi-board token', () => {
    const prompt = buildAgentPrompt('claude-code', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).not.toContain('?board=')
  })

  it('never contains an unfilled placeholder token', () => {
    const prompt = buildAgentPrompt('claude-code', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).not.toContain('<token>')
    expect(prompt).not.toContain('<your-install>')
  })
})

describe('buildAgentPrompt — claude-desktop', () => {
  it('includes a JSON config block with the real url and bearer header', () => {
    const prompt = buildAgentPrompt('claude-desktop', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).toContain('claude_desktop_config.json')
    expect(prompt).toContain('"mcpServers"')
    expect(prompt).toContain(`"url": "${ORIGIN}/api/v1/mcp"`)
    expect(prompt).toContain(`"Authorization": "Bearer ${TOKEN}"`)
  })

  it('includes the board query param in the url for a single-board token', () => {
    const prompt = buildAgentPrompt('claude-desktop', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: 'roadmap',
    })
    expect(prompt).toContain(`"url": "${ORIGIN}/api/v1/mcp?board=roadmap"`)
  })
})

describe('buildAgentPrompt — cursor-vscode', () => {
  it('includes both Cursor and VS Code config file locations and a "type": "http" JSON block', () => {
    const prompt = buildAgentPrompt('cursor-vscode', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).toContain('.cursor/mcp.json')
    expect(prompt).toContain('.vscode/mcp.json')
    expect(prompt).toContain('"type": "http"')
    expect(prompt).toContain(`"url": "${ORIGIN}/api/v1/mcp"`)
    expect(prompt).toContain(`"Authorization": "Bearer ${TOKEN}"`)
  })
})

describe('buildAgentPrompt — claude-code-cli', () => {
  it('installs the votepit-cli skill via degit into .claude/skills', () => {
    const prompt = buildAgentPrompt('claude-code-cli', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).toContain(
      'npx degit silvio-l/Votepit/skills/votepit-cli .claude/skills/votepit-cli',
    )
  })

  it('includes install, env var configuration, and a verification command', () => {
    const prompt = buildAgentPrompt('claude-code-cli', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).toContain('@votepit/cli')
    expect(prompt).toContain(`VOTEPIT_URL="${ORIGIN}"`)
    expect(prompt).toContain(`VOTEPIT_TOKEN="${TOKEN}"`)
    expect(prompt).toContain('@votepit/cli board')
    expect(prompt).not.toContain('VOTEPIT_BOARD')
  })

  it('includes VOTEPIT_BOARD for a single-board token', () => {
    const prompt = buildAgentPrompt('claude-code-cli', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: 'roadmap',
    })
    expect(prompt).toContain('VOTEPIT_BOARD="roadmap"')
  })

  it('never contains an unfilled placeholder token', () => {
    const prompt = buildAgentPrompt('claude-code-cli', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).not.toContain('<token>')
    expect(prompt).not.toContain('<your-install>')
  })
})

describe('buildAgentPrompt — generic-cli', () => {
  it('includes install, env var configuration, and a verification command', () => {
    const prompt = buildAgentPrompt('generic-cli', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: null,
    })
    expect(prompt).toContain('@votepit/cli')
    expect(prompt).toContain(`VOTEPIT_URL="${ORIGIN}"`)
    expect(prompt).toContain(`VOTEPIT_TOKEN="${TOKEN}"`)
    expect(prompt).toContain('votepit board')
    expect(prompt).not.toContain('VOTEPIT_BOARD')
  })

  it('includes VOTEPIT_BOARD for a single-board token', () => {
    const prompt = buildAgentPrompt('generic-cli', {
      origin: ORIGIN,
      token: TOKEN,
      boardSlug: 'roadmap',
    })
    expect(prompt).toContain('VOTEPIT_BOARD="roadmap"')
  })
})

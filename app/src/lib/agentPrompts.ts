/**
 * Copy-paste prompts for AI coding agents to self-configure a Votepit
 * MCP/CLI integration, filled in with the actual origin, revealed token, and
 * (when unambiguous) a resolved board slug.
 *
 * Used by ApiTokensPage's "Set up an agent" subsection, shown right after a
 * token is revealed — the plaintext token is only ever available client-side
 * at that moment, so this is the only place these prompts can be built.
 *
 * Pure functions, no React — unit-testable without rendering the page. The
 * generated text is intentionally English throughout (it targets an AI
 * coding agent, not a human reader of the surrounding UI).
 */

export type AgentTarget =
  | 'claude-code'
  | 'claude-code-cli'
  | 'claude-desktop'
  | 'cursor-vscode'
  | 'generic-cli'

export const AGENT_TARGETS: AgentTarget[] = [
  'claude-code',
  'claude-code-cli',
  'claude-desktop',
  'cursor-vscode',
  'generic-cli',
]

export interface AgentPromptOptions {
  /** `window.location.origin` — e.g. "https://feedback.example.com", no trailing slash. */
  origin: string
  /** The freshly revealed plaintext bearer token. */
  token: string
  /**
   * The token's single granted board slug, when it grants exactly one board
   * (the server resolves that board automatically — this is a helpful,
   * concrete hint, not a required parameter). Pass `null` for a multi-board
   * token: which board an agent should target is ambiguous in that case, so
   * no `?board=` value is filled in (a placeholder would violate the "must
   * be directly actionable" requirement for these prompts).
   */
  boardSlug: string | null
}

function mcpUrl({ origin, boardSlug }: AgentPromptOptions): string {
  const base = `${origin}/api/v1/mcp`
  return boardSlug === null ? base : `${base}?board=${boardSlug}`
}

function boardNote(boardSlug: string | null): string {
  return boardSlug === null
    ? ''
    : ` (this token is scoped to the "${boardSlug}" board, included above as ?board=${boardSlug})`
}

function buildClaudeCodePrompt(opts: AgentPromptOptions): string {
  const url = mcpUrl(opts)
  return [
    'Add my Votepit board as an MCP server and verify the connection.',
    '',
    'Run:',
    '',
    `claude mcp add --transport http votepit ${url} --header "Authorization: Bearer ${opts.token}"`,
    '',
    `Then run \`claude mcp list\` and confirm "votepit" is listed and connected${boardNote(opts.boardSlug)}.`,
    'Once added, use the votepit MCP tools (get_board, list_ideas, get_idea, create_idea) to read and create ideas on this board.',
  ].join('\n')
}

function buildClaudeDesktopPrompt(opts: AgentPromptOptions): string {
  const url = mcpUrl(opts)
  const configBlock = JSON.stringify(
    {
      mcpServers: {
        votepit: {
          url,
          headers: { Authorization: `Bearer ${opts.token}` },
        },
      },
    },
    null,
    2,
  )
  return [
    'Configure Claude Desktop to use my Votepit board as an MCP server, then confirm it works.',
    '',
    '1. Open (creating it if missing) the Claude Desktop config file:',
    '   - macOS: ~/Library/Application Support/Claude/claude_desktop_config.json',
    '   - Windows: %APPDATA%\\Claude\\claude_desktop_config.json',
    '',
    '2. Merge this entry into "mcpServers" (keep any existing entries):',
    '',
    configBlock,
    '',
    `3. Restart Claude Desktop and confirm "votepit" appears as a connected MCP server${boardNote(opts.boardSlug)}.`,
    'Once connected, use its tools (get_board, list_ideas, get_idea, create_idea) to read and create ideas on this board.',
  ].join('\n')
}

function buildCursorVscodePrompt(opts: AgentPromptOptions): string {
  const url = mcpUrl(opts)
  const configBlock = JSON.stringify(
    {
      mcpServers: {
        votepit: {
          type: 'http',
          url,
          headers: { Authorization: `Bearer ${opts.token}` },
        },
      },
    },
    null,
    2,
  )
  return [
    'Configure my editor (Cursor or VS Code) to use my Votepit board as an MCP server, then confirm it works.',
    '',
    '1. Create or edit an MCP config file:',
    '   - Cursor: .cursor/mcp.json (project) or ~/.cursor/mcp.json (global)',
    '   - VS Code: .vscode/mcp.json (project) or the user-level MCP settings',
    '',
    '2. Merge this entry into "mcpServers" (keep any existing entries):',
    '',
    configBlock,
    '',
    `3. Reload the MCP servers and confirm "votepit" is connected${boardNote(opts.boardSlug)}.`,
    'Once connected, use its tools (get_board, list_ideas, get_idea, create_idea) to read and create ideas on this board.',
  ].join('\n')
}

function cliEnvBlock(opts: AgentPromptOptions): string {
  const boardLine = opts.boardSlug === null ? '' : `\nexport VOTEPIT_BOARD="${opts.boardSlug}"`
  return `export VOTEPIT_URL="${opts.origin}"\nexport VOTEPIT_TOKEN="${opts.token}"${boardLine}`
}

function buildGenericCliPrompt(opts: AgentPromptOptions): string {
  return [
    'Set up the @votepit/cli tool to talk to my Votepit board, then verify it works.',
    '',
    '1. Install it (or use it without installing via npx):',
    '   npm i -g @votepit/cli',
    '',
    '2. Configure it with these environment variables:',
    '',
    cliEnvBlock(opts),
    '',
    '3. Verify it works by running `votepit board` and confirming it prints the board as JSON.',
    'Once verified, use `votepit ideas list`, `votepit ideas get <id>`, and `votepit ideas create --title <t> --body <b>` to read and create ideas on this board.',
  ].join('\n')
}

function buildClaudeCodeCliPrompt(opts: AgentPromptOptions): string {
  return [
    'Set up the @votepit/cli tool and its matching Claude Code skill for my Votepit board (a lighter-weight alternative to the MCP server), then verify it works.',
    '',
    '1. Install the votepit-cli skill into this project:',
    '',
    '   npx degit silvio-l/Votepit/skills/votepit-cli .claude/skills/votepit-cli',
    '',
    '2. Configure these environment variables (needed by both the skill and the CLI it wraps):',
    '',
    cliEnvBlock(opts),
    '',
    '3. Verify it works by running `npx @votepit/cli board` and confirming it prints the board as JSON.',
    'Once verified, use the votepit-cli skill (or `votepit ideas list` / `get <id>` / `create --title <t> --body <b>` directly) to read and create ideas on this board — this uses far less context than the MCP server, since each call is a plain CLI invocation instead of an MCP round-trip.',
  ].join('\n')
}

/** Builds the full copy-paste prompt for the given agent target. */
export function buildAgentPrompt(target: AgentTarget, opts: AgentPromptOptions): string {
  switch (target) {
    case 'claude-code':
      return buildClaudeCodePrompt(opts)
    case 'claude-code-cli':
      return buildClaudeCodeCliPrompt(opts)
    case 'claude-desktop':
      return buildClaudeDesktopPrompt(opts)
    case 'cursor-vscode':
      return buildCursorVscodePrompt(opts)
    case 'generic-cli':
      return buildGenericCliPrompt(opts)
    default: {
      const exhaustive: never = target
      throw new Error(`Unknown agent target: ${String(exhaustive)}`)
    }
  }
}

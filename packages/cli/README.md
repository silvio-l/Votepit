# @votepit/cli

Zero-dependency Node CLI for Votepit's Agent API (`/api/v1/*`) — the same
four operations the [MCP server](../../documentation/mcp-server.md) wraps,
invoked as plain shell commands instead of an MCP round-trip. Useful for
agents/scripts that want the Agent API without paying MCP's token/context
overhead.

Full contract (auth, rate limits, error shapes): see
[`../../documentation/api-reference.md`](../../documentation/api-reference.md).
This CLI does not duplicate that logic or invent its own error format — it
passes the server's response straight through.

## Install

```bash
npm i -g @votepit/cli
```

or run it without installing:

```bash
npx @votepit/cli board
```

Requires Node 18+ (uses the built-in `fetch`).

## Configuration

Every command needs a Votepit install URL and a bearer token, each settable
as a flag or an environment variable (a flag always wins):

| Flag       | Env var         | Required | Notes                                          |
| ---------- | --------------- | -------- | ----------------------------------------------- |
| `--url`    | `VOTEPIT_URL`   | yes      | e.g. `https://feedback.example.com`             |
| `--token`  | `VOTEPIT_TOKEN` | yes      | bearer token, from `/admin/tokens`              |
| `--board`  | `VOTEPIT_BOARD` | only for multi-board tokens | board slug — see the API reference |

Missing `--url`/`--token` fails immediately with a clear message, before any
network call. A missing/wrong `--board` on a multi-board token returns
whatever error the server gives (`400 board_required` / `403
board_not_granted`) — the CLI doesn't invent its own text for that.

```bash
export VOTEPIT_URL="https://feedback.example.com"
export VOTEPIT_TOKEN="<token>"
```

## Output

JSON on stdout, exit code `0`, on success. On failure, the server's error
message goes to stderr and the process exits non-zero — nothing is printed
to stdout in that case, so `votepit ... | jq` is safe to script against.

## Commands

```bash
# Get the resolved board
votepit board

# List ideas (all filters optional)
votepit ideas list --status open --sort top --page 1

# Get a single idea by id
votepit ideas get 42

# Create an idea (requires a write-scoped token)
votepit ideas create --title "Dark mode" --body "Would love a dark theme."
```

`votepit --help` (or no arguments) prints full usage.

## Claude Code skill

An Agent Skill wrapping this CLI lives at
[`../../skills/votepit-cli/`](../../skills/votepit-cli/) — it lets Claude
read/create ideas on a Votepit board via `votepit` instead of configuring
the MCP server.

Install it into the current project:

```bash
npx degit silvio-l/Votepit/skills/votepit-cli .claude/skills/votepit-cli
```

or for every project (personal, global install):

```bash
npx degit silvio-l/Votepit/skills/votepit-cli ~/.claude/skills/votepit-cli
```

Both fetch just the `skills/votepit-cli/` directory from the public repo (no
git history, no full clone). Then set `VOTEPIT_URL`/`VOTEPIT_TOKEN` (see
above) and ask Claude Code to use the Votepit skill.

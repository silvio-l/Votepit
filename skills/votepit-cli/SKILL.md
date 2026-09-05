---
name: votepit-cli
description: >-
  Read and create ideas on a Votepit feature-voting board (self-hosted or
  Votepit Cloud) using its Agent API, via the @votepit/cli command-line tool
  instead of configuring an MCP server. Use whenever the user mentions
  Votepit, a Votepit API token, a Votepit board/idea, or asks for an
  alternative to the Votepit MCP server — even if they just say "check my
  Votepit board" or paste a Votepit API token without naming the tool.
---

# Votepit CLI

`@votepit/cli` is a zero-dependency Node CLI that talks directly to Votepit's
Agent API (`/api/v1/*`) — the same 4 operations the Votepit MCP server
wraps, as plain shell commands. Prefer it over setting up the MCP server
when a quick read/write is all that's needed.

## Setup

1. Get the required config from the user's environment or ask for it:
   - `VOTEPIT_URL` — the Votepit install's origin (e.g.
     `https://feedback.example.com` or a Cloud domain).
   - `VOTEPIT_TOKEN` — a bearer token from that install's `/admin/tokens`
     page (or `/{account-slug}/admin/tokens` on Votepit Cloud).
   - `VOTEPIT_BOARD` — a board slug. Only needed if the token grants more
     than one board; omit it otherwise (the server resolves the single
     granted board automatically).
2. If any of `VOTEPIT_URL`/`VOTEPIT_TOKEN` is missing, ask the user for it
   before running any command — don't guess or invent a value.
3. Run the CLI via `npx @votepit/cli <command>` (no install needed) or, if
   already installed globally, `votepit <command>`.

## Commands

- `votepit board` — get the resolved board.
- `votepit ideas list [--status <s>] [--sort <s>] [--page <n>]` — list ideas.
- `votepit ideas get <id>` — get one idea by numeric id.
- `votepit ideas create --title <t> --body <b>` — create an idea (requires a
  write-scoped token; a read-scoped token gets a clear error).

Output is JSON on stdout on success. On failure, the CLI prints the
server's own error message to stderr and exits non-zero — read that message
rather than guessing at the cause (e.g. a wrong `--board`, an expired
token, a read-only token used for `create`).

Run `npx @votepit/cli --help` (or `votepit --help`) for the full flag
reference; don't re-derive it here. Full API contract, error shapes, and
rate limits: `../../documentation/api-reference.md` and
`../../packages/cli/README.md` in the same Votepit repo (for a
self-hosted install, the equivalent docs ship with that install's own
`documentation/` directory).

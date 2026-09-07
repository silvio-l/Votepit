# MCP server

Votepit exposes a board's Agent API as an [MCP](https://modelcontextprotocol.io) (Model
Context Protocol) endpoint, so AI agents/assistants can read and create ideas on a board
using the same admin-issued tokens as the REST Agent API. Implemented as a hand-rolled
JSON-RPC 2.0 server (`src/Http/Action/McpAction.php`) — no MCP SDK dependency.

## Endpoint

```
POST /api/v1/mcp
Authorization: Bearer <token>
Content-Type: application/json
```

Same bearer-token authentication, same board-grant/scope model, and the **same
rate-limit buckets** (`apitoken:read`/`apitoken:write`) as the plain REST Agent API — see
[`api-reference.md`](api-reference.md#authentication) for how tokens are issued (subject to
`PlanPolicy::agentApiAllowed()`) and their trust boundary. A token cannot read or write a
board outside its grant through MCP any more than it can through REST, and the resolved
board follows the same `?board=<slug>` query-parameter rule described there.

Protocol version implemented: `2024-11-05`. Transport is plain HTTP JSON-RPC — **every**
response, including protocol-level errors, is HTTP 200 with a JSON-RPC envelope in the
body; the HTTP status code itself is not used to signal JSON-RPC errors. **Not
implemented:** JSON-RPC batch requests, `resources/*` methods, SSE/streaming.

## Methods

| Method | Purpose |
|---|---|
| `initialize` | Handshake — returns `protocolVersion: "2024-11-05"`, `capabilities: {tools: {}}`, `serverInfo: {name: "votepit-mcp", version: "1.0.0"}`. |
| `ping` | Liveness check. |
| `tools/list` | Lists the 4 available tools (see below). |
| `tools/call` | Invokes one tool by name. |

Any other method returns JSON-RPC error `-32601 Method not found`.

## Tools

Four tools, a direct 1:1 mapping onto the REST Agent API endpoints — no duplicated
query/validation/moderation logic between the two surfaces:

| Tool | Arguments | Maps to |
|---|---|---|
| `get_board` | none | `GET /api/v1/board` |
| `list_ideas` | `status` (string, optional), `sort` (string, optional), `page` (int ≥ 1, optional) | `GET /api/v1/ideas` |
| `get_idea` | `id` (int, required) | `GET /api/v1/ideas/{id}` |
| `create_idea` | `title` (string, required, 3–200 chars), `body` (string, required, ≥10 chars) | `POST /api/v1/ideas` |

`create_idea` requires a `write`-scoped token (JSON-RPC error `-32000` for a `read`-scoped
one) and consumes the `apitoken:write` bucket — **the same bucket** a `POST /api/v1/ideas`
REST call would consume, keyed identically (`apitoken:write:<token_id>`). A token doesn't
get separate MCP and REST write budgets.

## Request/response shape

Standard JSON-RPC 2.0. Example `tools/call`, sent with `curl` (useful for manually
verifying a token/endpoint outside any MCP client):

```bash
curl -s https://<your-install>/api/v1/mcp \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc": "2.0", "id": 1, "method": "tools/call",
       "params": {"name": "list_ideas", "arguments": {"status": "open", "page": 1}}}'
```

Tool results are wrapped in the standard MCP content envelope:

```json
{"jsonrpc": "2.0", "id": 1,
 "result": {"content": [{"type": "text", "text": "<json-encoded result>"}], "isError": false}}
```

On a tool-level failure (e.g. invalid arguments, not found, rate limited), `isError` is
`true` and `content[0].text` carries the error description — this is still a
JSON-RPC-*success* envelope (the RPC call itself succeeded; the tool call failed).
JSON-RPC-level errors use the standard `error` field instead:

| Code | Meaning |
|---|---|
| `-32700` | Parse error (invalid JSON) |
| `-32600` | Invalid Request (also returned for batch requests — unsupported) |
| `-32601` | Method or tool not found |
| `-32602` | Invalid params |
| `-32000` | Rate limit exceeded |

## Configuring an MCP client

Point any MCP-over-HTTP-capable client at `https://<your-install>/api/v1/mcp` with the
bearer token as the `Authorization` header. There is no separate MCP-specific
configuration on the server side — issuing a token in the admin UI (`/admin/tokens`) is
the only setup step; the same token works for both `/api/v1/mcp` and the plain REST
endpoints. Replace `https://<your-install>/api/v1/mcp` and `<token>` below with your
account's actual values.

> **On the Community Edition (self-hosted)**, `<your-install>` is the domain where you
> deployed Votepit yourself (e.g. `feedback.example.com`), and tokens live at
> `https://<your-install>/admin/tokens`.
>
> **On [Votepit Cloud](https://votepit.com)**, there's no separate install to point at —
> every account shares one endpoint. Use your Cloud instance's own domain:
>
> ```
> https://<your-cloud-domain>/api/v1/mcp
> ```
>
> and issue the token from your account's admin area at
> `https://<your-cloud-domain>/{your-account-slug}/admin/tokens` (Account → Admin → API
> tokens), choosing which board(s) to grant it. The endpoint itself never includes your
> account or board slug — the token's grant alone determines which board(s) a request can
> read/write, exactly like the self-hosted case above.

### Claude Code

```bash
claude mcp add --transport http votepit https://<your-install>/api/v1/mcp \
  --header "Authorization: Bearer <token>"
```

`--scope project` instead writes the entry to a checked-in `.mcp.json` (don't commit a
real token that way — use `--scope local` or `--scope user` for a personal token, or
reference an environment variable in a hand-edited `.mcp.json`). Verify with
`claude mcp list` / `claude mcp get votepit`.

### Claude Desktop

Add to `claude_desktop_config.json` (macOS:
`~/Library/Application Support/Claude/claude_desktop_config.json`; Windows:
`%APPDATA%\Claude\claude_desktop_config.json`), then restart Claude Desktop:

```json
{
  "mcpServers": {
    "votepit": {
      "url": "https://<your-install>/api/v1/mcp",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

### VS Code / Cursor

Add an `mcp.json` (VS Code: `.vscode/mcp.json` or the user-level MCP config; Cursor:
`.cursor/mcp.json` in the project or `~/.cursor/mcp.json` globally):

```json
{
  "mcpServers": {
    "votepit": {
      "type": "http",
      "url": "https://<your-install>/api/v1/mcp",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

In every case, treat the token like a password: prefer a per-tool, revocable token issued
just for that client (`/admin/tokens`) over reusing one token everywhere, and don't commit
it into a project-scoped/checked-in MCP config file. On Votepit Cloud,
substitute your Cloud account's own MCP URL for `<your-install>` in every snippet
above — see the callout under "Configuring an MCP client".

## Alternative: `@votepit/cli`

For agents/scripts that want to avoid MCP's protocol overhead, `@votepit/cli`
(`../packages/cli/`) talks to the same 4 Agent API endpoints directly as
plain shell commands (`votepit board`, `votepit ideas list`, …), using the
same bearer token. Same capability, no JSON-RPC envelope. See
[`cli.md`](cli.md) for install, commands, and the matching Claude Code
Agent Skill.

## Testing

Server-side behavior (including every error code above) is covered by
`tests/Http/McpEndpointTest.php`.

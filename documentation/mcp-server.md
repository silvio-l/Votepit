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

Same bearer-token authentication, same board-scoping, and the **same rate-limit buckets**
(`apitoken:read`/`apitoken:write`) as the plain REST Agent API — see
[`api-reference.md`](api-reference.md#authentication) for how tokens are issued (subject to
`PlanPolicy::agentApiAllowed()`) and their trust boundary. A token bound to board A can never read or write board B
through MCP any more than it can through REST.

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

`create_idea` consumes the `apitoken:write` bucket — **the same bucket** a
`POST /api/v1/ideas` REST call would consume, keyed identically
(`apitoken:write:<token_id>`). A token doesn't get separate MCP and REST write budgets.

## Request/response shape

Standard JSON-RPC 2.0. Example `tools/call`:

```json
{"jsonrpc": "2.0", "id": 1, "method": "tools/call",
 "params": {"name": "list_ideas", "arguments": {"status": "open", "page": 1}}}
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
configuration on the server side — issuing a token in the admin UI
(`/admin/boards/{slug}/tokens`) is the only setup step; the same token works for both
`/api/v1/mcp` and the plain REST endpoints.

## Testing

Server-side behavior (including every error code above) is covered by
`tests/Http/McpEndpointTest.php`.

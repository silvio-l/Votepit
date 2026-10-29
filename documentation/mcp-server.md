# MCP server

Votepit exposes a board's Agent API as an [MCP](https://modelcontextprotocol.io) (Model
Context Protocol) endpoint, so AI agents/assistants can read, search, create, vote on,
comment on and (with the `moderate` grant) triage ideas on a board using the same admin-issued tokens as the REST Agent API. Implemented as a hand-rolled
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
| `tools/list` | Lists the available tools (see below). |
| `tools/call` | Invokes one tool by name. |

Any other method returns JSON-RPC error `-32601 Method not found`.

## Tools

One tool per REST Agent API endpoint — the tools call the same handlers, so there is no
duplicated query/validation/moderation logic between the two surfaces. The grant column
is the minimum per-board grant level (see
[`api-reference.md`](api-reference.md#authentication)):

| Tool | Arguments | Grant | Maps to |
|---|---|---|---|
| `get_board` | none | read | `GET /api/v1/board` (incl. status registry + tags) |
| `list_ideas` | `status`, `status_key`, `q`, `sort`, `page` (all optional); `moderation_state: "pending"` (moderate) | read | `GET /api/v1/ideas` |
| `search_ideas` | `q` (required), `page` | read | `GET /api/v1/ideas?q=` |
| `get_idea` | `id` | read | `GET /api/v1/ideas/{id}` |
| `create_idea` | `title` (3–200 chars), `body` (≥10 chars) | write | `POST /api/v1/ideas` |
| `vote_idea` | `id`, `value` (`up`/`down`/`none`) | write | `POST /api/v1/ideas/{id}/vote` |
| `comment_on_idea` | `id`, `body` (≤500 chars) | write | `POST /api/v1/ideas/{id}/comments` |
| `set_idea_status` | `id`, `status` (a key from `get_board`), `reason` (optional; required if the board demands it, else `status_reason_required`) | moderate | `POST /api/v1/ideas/{id}/status` |
| `add_idea_tag` | `id`, `tag_id` | moderate | `POST /api/v1/ideas/{id}/tags` |
| `remove_idea_tag` | `id`, `tag_id` | moderate | `DELETE /api/v1/ideas/{id}/tags/{tagId}` |
| `merge_ideas` | `id` (duplicate), `target_id` | moderate | `POST /api/v1/ideas/{id}/merge` |
| `unmerge_idea` | `id` | moderate | `POST /api/v1/ideas/{id}/unmerge` |
| `get_board_branding` | none | read | `GET /api/v1/board/branding` |
| `update_board_branding` | the fields of `PATCH /api/v1/board/branding` (partial) | manage | `PATCH /api/v1/board/branding` |
| `upload_board_asset` | `kind` (`cover`, `favicon`, `og`, `logo`, `wordmark`, `wordmark_light`), `image_base64` (standard base64, no `data:` prefix), `content_type` (optional: `image/png`, `image/jpeg`, `image/webp`) | manage | `POST /api/v1/board/assets/{kind}` |
| `delete_board_asset` | `kind` | manage | `DELETE /api/v1/board/assets/{kind}` |

`create_idea` with a `read`-scoped token is a JSON-RPC error `-32000`; the other write
tools report an insufficient grant as a tool error (`isError: true`, `error.key:
insufficient_scope`). Every write tool consumes the `apitoken:write` bucket — **the same
bucket** the REST write endpoints consume, keyed identically
(`apitoken:write:<token_id>`). A token doesn't get separate MCP and REST write budgets.
Semantics (vote identity, pre-moderation, attribution) are those of the REST endpoints,
see [`api-reference.md`](api-reference.md#endpoints).

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

On a tool-level failure (e.g. invalid arguments, not found, insufficient grant), `isError`
is `true` and `content[0].text` carries the error description (for the idea write tools:
the REST error body, `{"error": {"key": …, "message": …}}`) — this is still a
JSON-RPC-*success* envelope (the RPC call itself succeeded; the tool call failed).
JSON-RPC-level errors use the standard `error` field instead:

| Code | Meaning |
|---|---|
| `-32700` | Parse error (invalid JSON) |
| `-32600` | Invalid Request (also returned for batch requests — unsupported) |
| `-32601` | Method or tool not found |
| `-32602` | Invalid params |
| `-32000` | Rate limit exceeded |

## OAuth sign-in

Instead of pasting a static token, an MCP client that speaks the
[MCP authorization spec](https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization)
(Claude, ChatGPT, MCP Inspector, …) can sign in with **OAuth 2.1**. Only the MCP endpoint
accepts OAuth access tokens; the REST endpoints stay static-token only.

**Discovery.** `POST /api/v1/mcp` without a token answers `401` with

```
WWW-Authenticate: Bearer resource_metadata="https://<your-install>/.well-known/oauth-protected-resource/api/v1/mcp"
```

That RFC 9728 document names the authorization server (the install's own base URL), whose
RFC 8414 metadata lives at `/.well-known/oauth-authorization-server`.

| Endpoint | Purpose |
|---|---|
| `POST /oauth/register` | RFC 7591 dynamic client registration — **public clients only** (`token_endpoint_auth_method: none`), body ≤ 8 KB, ≤ 5 redirect URIs. |
| `GET /oauth/authorize` | Consent screen in the web app (login required). |
| `POST /oauth/token` | `authorization_code` (PKCE) and `refresh_token` grants, `application/x-www-form-urlencoded` only. |
| `POST /oauth/revoke` | RFC 7009 revocation; always `200`, a refresh token revokes the whole grant. |

**Rules the server enforces** (deliberately strict — see `src/Security/OAuthServer/`):

- **PKCE `S256` is mandatory**; `plain` and missing challenges are rejected.
- **Redirect URIs:** `https://…` or loopback `http://127.0.0.1|localhost|[::1]:<port>/…`, no
  fragment, no userinfo, compared by **exact string match** at authorize and token time.
  **Custom schemes (`cursor://`, `vscode://`, …) are not accepted** — clients that need
  them keep using a static token (see the VS Code / Cursor snippet below).
- `state` is passed through unchanged; responses carry `iss` (RFC 9207).
- `client_name` is stored and shown as plain text only (never markup, no logos).
- Authorization codes live 2 minutes and are single-use — replaying one revokes the grant it
  produced. Access tokens (`vpat_…`) live 1 hour; refresh tokens (`vprt_…`) live 30 days
  and **rotate on every use** — presenting an already used refresh token revokes the whole
  grant. Codes and tokens are stored only as SHA-256 hashes.

**Consent = a token grant.** On the consent screen the user (owner or admin of the account)
picks **one account**, the **boards** and a **level per board** (`read`/`write`/`moderate`;
`manage` is only issued by an admin on a static token, never through OAuth consent).
The client's requested `scope` is only a ceiling — the user can grant less, never more.
Approving creates exactly the same board grant as an admin-issued token, so scoping, rate
limits, the `moderate` issuer-role rule and the plan gate (`PlanPolicy::agentApiAllowed()`)
apply unchanged. Grants appear on the account's API-token page (badged "OAuth") and are
revoked there like any token. The user's right to delegate (still owner/admin, not blocked,
plan still includes the Agent API) is re-checked at code exchange and on every refresh —
losing it revokes the grant. **Deny** sends the client `error=access_denied`.

Rate limits: `oauth:register` (per IP) + `oauth:register:global`, `oauth:token`,
`oauth:revoke` (per IP) and `oauth:consent` (per user) — see
[`configuration.md`](configuration.md).

**Not implemented:** confidential clients, client secrets, custom-scheme redirects, CORS for
browser-based MCP clients, OAuth for the REST endpoints.

### Claude Code with OAuth

```bash
claude mcp add --transport http votepit https://<your-install>/api/v1/mcp
```

Without a `--header`, Claude Code discovers the OAuth server on the first `401`; run `/mcp`
and choose **Authenticate** to open the consent screen.

## Configuring an MCP client

Point any MCP-over-HTTP-capable client at `https://<your-install>/api/v1/mcp` with the
bearer token as the `Authorization` header (or let an OAuth-capable client sign in, see
[OAuth sign-in](#oauth-sign-in)). There is no separate MCP-specific
configuration on the server side — issuing a token in the admin UI (`/admin/tokens`) is
the only setup step; the same token works for both `/api/v1/mcp` and the plain REST
endpoints. Replace `https://<your-install>/api/v1/mcp` and `<token>` below with your
account's actual values.

> **On the Community Edition (self-hosted)**, `<your-install>` is the domain where you
> deployed Votepit yourself (e.g. `feedback.example.com`), and tokens live at
> `https://<your-install>/admin/tokens`.
>
> **On [Votepit Cloud](https://votepit.com)**, there's no separate install to point at — <!-- export-ok: cloud-origin -->
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
(`../packages/cli/`) talks to the board/idea read and create endpoints directly as
plain shell commands (`votepit board`, `votepit ideas list`, …), using the
same bearer token. Same capability, no JSON-RPC envelope. See
[`cli.md`](cli.md) for install, commands, and the matching Claude Code
Agent Skill.

## Testing

Server-side behavior (including every error code above) is covered by
`tests/Http/McpEndpointTest.php` and `tests/Http/McpWriteToolsTest.php`; OAuth sign-in by
`tests/Http/OAuthServerTest.php` and `tests/Security/OAuthServer/`.

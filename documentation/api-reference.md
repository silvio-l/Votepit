# REST API reference

This covers the token-authenticated **Agent API** (`/api/v1/*`) meant for external
integrations. The React SPA talks to a much larger set of session-authenticated routes
(admin, boards, ideas, comments, account, …) that are internal to the app and not a
stable public contract — those aren't documented here; read `src/Http/AppFactory.php` and
the corresponding `src/Http/Action/*` class if you need to understand a specific one.

## Authentication

Every Agent API route requires:

```
Authorization: Bearer <token>
```

Quick check that a token works:

```bash
curl -s https://<your-install>/api/v1/board \
  -H "Authorization: Bearer <token>"
```

Tokens are created and revoked in the admin UI at the account level
(`GET/POST /admin/tokens`, `POST /admin/tokens/{id}/revoke`, session-authenticated,
requires account-admin role). **A token grants a settable SET of the account's boards**,
plus a coarse `read`/`write` scope — it can never read or write a board outside that set,
regardless of what the request asks for, and a `read`-scoped token can never call the one
write endpoint (`POST /api/v1/ideas` / the `create_idea` MCP tool). The plaintext token is
shown exactly once, at creation time; only its SHA-256 hash is stored server-side,
compared with a constant-time comparison. Creating a new token is gated by the injected
`PlanPolicy::agentApiAllowed()` (Community's default, `UnrestrictedPlanPolicy`, always
allows it); an invalid/missing bearer token, a token without write scope on a write
request, or a token for a board outside its grant, results in `401`/`403`.

A token that grants exactly one board resolves it automatically. A token that grants
more than one board requires an explicit `?board=<slug>` query parameter on every request
— naming a board the token isn't granted returns `403 board_not_granted`; omitting it on
a multi-board token returns `400 board_required`.

Agent API requests bypass session cookies and CSRF entirely — they are a separate trust
boundary (see [`architecture.md`](architecture.md#request-pipeline)).

## Rate limits

Two buckets, keyed per token (`config.php` → `rate_limits`, see
[`configuration.md`](configuration.md)):

- `apitoken:read` — 120/minute default. Covers `GET /api/v1/board`, `GET /api/v1/ideas`,
  `GET /api/v1/ideas/{id}`.
- `apitoken:write` — 20/hour default. Covers `POST /api/v1/ideas`.

The MCP endpoint (`POST /api/v1/mcp`, see [`mcp-server.md`](mcp-server.md)) reuses the
**same buckets and the same per-token key** — a token's REST usage and MCP usage share one
budget, not two independent ones.

## Endpoints

Every endpoint below resolves its board as described above (`?board=<slug>` when the
token grants more than one).

### `GET /api/v1/board`

Returns the resolved board.

### `GET /api/v1/ideas`

List ideas on the resolved board. Query parameters:

| Param | Type | Notes |
|---|---|---|
| `status` | string | optional, filter by status |
| `sort` | string | optional |
| `page` | int | optional, ≥ 1 |

### `GET /api/v1/ideas/{id}`

Fetch a single idea by numeric ID (`{id:[0-9]+}`). Returns 404 if the idea doesn't belong
to the resolved board.

### `POST /api/v1/ideas`

Create a new idea on the resolved board. Requires a `write`-scoped token — a `read`-scoped
token gets `403 insufficient_scope`.

| Field | Type | Constraint |
|---|---|---|
| `title` | string | required |
| `body` | string | required |

```bash
curl -s https://<your-install>/api/v1/ideas \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"title": "Dark mode", "body": "Would love a dark theme for the board."}'
```

Consumes the `apitoken:write` rate-limit bucket.

## Errors

Standard HTTP status codes: `401` (missing/invalid token), `403` (token valid but not
authorized for the requested board/action, or Agent API not available on the current
plan), `404` (resource not found or not on this token's board — the API deliberately does
not distinguish "doesn't exist" from "exists on another board", to avoid leaking
cross-board existence), `422` (validation failure), `429` (rate limit exceeded). Response
bodies are JSON; exact error shapes are not yet part of a versioned contract — treat the
HTTP status code as authoritative.

## Other trust boundaries

One more authenticated surface exists outside this Agent API, documented for completeness
but not part of the "external integration" contract above:

- **Operator panel** (`/operator/*`) — platform-wide, session-authenticated, requires the
  `operator` authorization level (`users.is_operator`, settable only via direct database
  access, no signup path grants it). Relevant only to whoever runs the installation, not
  to external integrators.

Extensions registered in `config.php` may add routes of their own (with their own
authentication); those are documented by the extension, not here.

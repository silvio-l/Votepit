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
each with a grant level — it can never read or write a board outside that set, regardless
of what the request asks for:

| Grant | Allows |
|---|---|
| `read` | the board and its published ideas (incl. search) |
| `write` | plus create ideas, vote, comment — like a regular, non-moderating member |
| `moderate` | plus change status, assign/remove tags, merge/unmerge, read pending ideas; ideas it creates skip pre-moderation |
| `manage` | plus the board's branding and images (`/api/v1/board/branding`, `/api/v1/board/assets/{kind}`) |

`moderate` is an explicit grant (never implied by `write`) and is only effective while the
user who issued the token still holds a moderating account role (owner, admin or
moderator) — otherwise moderator actions return `403 token_issuer_not_moderator`.
`manage` is an explicit grant above `moderate` and is only effective while the issuing
user is still an owner or admin (the role the app requires for branding); otherwise the
token acts as `moderate`. A grant
that is too low returns `403 insufficient_scope`. The plaintext token is
shown exactly once, at creation time; only its SHA-256 hash is stored server-side,
compared with a constant-time comparison. Creating a new token is gated by the injected
`PlanPolicy::agentApiAllowed()` (Community's default, `UnrestrictedPlanPolicy`, always
allows it); an invalid/missing bearer token, a token without write scope on a write
request, or a token for a board outside its grant, results in `401`/`403`.

A token that grants exactly one board resolves it automatically. A token that grants
more than one board requires an explicit `?board=<slug>` query parameter on every request
— naming a board the token isn't granted returns `403 board_not_granted`; omitting it on
a multi-board token returns `400 board_required`.

**OAuth (MCP only).** `POST /api/v1/mcp` additionally accepts short-lived OAuth 2.1 access
tokens obtained through the consent flow described in
[`mcp-server.md`](mcp-server.md#oauth-sign-in). Each OAuth grant is an API-token row with the
same board set and levels, listed (badged "OAuth") and revocable on the same token page; the
REST endpoints above do not accept OAuth access tokens. An unauthenticated MCP call answers
`401` with `WWW-Authenticate: Bearer resource_metadata="…"` for discovery.

Agent API requests bypass session cookies and CSRF entirely — they are a separate trust
boundary (see [`architecture.md`](architecture.md#request-pipeline)).

## Rate limits

Two buckets, keyed per token (`config.php` → `rate_limits`, see
[`configuration.md`](configuration.md)):

- `apitoken:read` — 120/minute default. Covers `GET /api/v1/board`, `GET /api/v1/ideas`,
  `GET /api/v1/ideas/{id}`.
- `apitoken:write` — 20/hour default. Covers every write endpoint (`POST /api/v1/ideas`
  and everything under `/api/v1/ideas/{id}/…`).

The MCP endpoint (`POST /api/v1/mcp`, see [`mcp-server.md`](mcp-server.md)) reuses the
**same buckets and the same per-token key** — a token's REST usage and MCP usage share one
budget, not two independent ones.

## Endpoints

The machine-readable contract of every endpoint (request/response schemas, error codes)
is [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1); a test keeps it in sync with the
registered routes. Every endpoint below resolves its board as described above
(`?board=<slug>` when the token grants more than one). Ideas of another board or account
are always `404`; pending (not yet approved) ideas are invisible unless the token has the
`moderate` grant.

### `GET /api/v1/board`

Returns the resolved board, its status registry (`statuses[]` with `key`, `category`,
`label`, `is_hidden`, `allow_votes`, `allow_comments` — the keys are the valid values for
a status change, including board-defined custom statuses) and its `tags[]`
(`id`, `name`, `color`).

### `GET /api/v1/ideas`

List ideas on the resolved board. Query parameters:

| Param | Type | Notes |
|---|---|---|
| `status` | string | optional, filter by status category: `open`, `planned`, `in_progress`, `done`, `declined`, `archived` (matches every status in that category) |
| `status_key` | string | optional, filter by one exact status key (wins over `status`) |
| `q` | string | optional, title search (every word must match, prefix matching, ≤ 100 chars) — the board search of the app |
| `sort` | string | optional, `newest` (default) or `top` |
| `page` | int | optional, ≥ 1 |
| `moderation_state` | string | optional, only `pending`: the approval queue instead of the published list (requires `moderate`) |

Every idea reports `status` as its category (one of the six values above), plus
`status_key` (the exact status — equal to `status` unless the board defines custom
statuses), `status_category` (`inbox`, `planned`, `active`, `completed`, `declined`,
`archived`) and `status_label` (the board's English label for it).

### `GET /api/v1/ideas/{id}`

Fetch a single idea by numeric ID (`{id:[0-9]+}`). Returns 404 if the idea doesn't belong
to the resolved board.

### `POST /api/v1/ideas`

Create a new idea on the resolved board. Requires the `write` grant — a `read`-scoped
token gets `403 insufficient_scope`. The idea is attributed to the user who issued the
token. On a board with pre-moderation it follows the board's rule like any non-moderating
member's idea: it is stored as pending (the response carries `"moderation_state":
"pending"`, moderators get the usual notice) — only a `moderate` grant, or a
`write` grant whose issuing user a moderator has marked as a trusted author of the account,
publishes immediately. A published idea fires the `idea.created` webhook (and chat notification)
exactly like an idea submitted in the app. A frozen board returns `423 board_frozen`.

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

**Submission windows apply to tokens.** If the board has an optional submission window
(set by a board admin under *Deadlines*) and it is not open, the request is refused with
`423` and `submissions_not_open_yet` (before it opens) or `submissions_closed` (after it
closed); nothing is written. This also applies to tokens issued by the account owner or an
admin — only a signed-in owner/admin in the app may add ideas outside the window. The MCP
`create_idea` tool behaves the same way. Reading (`GET`) is never affected by a window.
### Idea actions

All take the idea's numeric ID in the path, consume the `apitoken:write` bucket and run
the app's own action logic — validation, the status registry, frozen-board and block
checks, follower notifications, webhooks (`idea.status_changed`, `idea.merged`) and the
chat fan-out behave exactly as for the same action in the UI.

| Endpoint | Body | Grant |
|---|---|---|
| `POST /api/v1/ideas/{id}/vote` | `{"value": "up" \| "down" \| "none"}` | `write` |
| `POST /api/v1/ideas/{id}/comments` | `{"body": "…"}` (plain text, ≤ 500 chars) | `write` |
| `POST /api/v1/ideas/{id}/status` | `{"status": "<status key>", "reason": "…"}` (`reason` optional, unless the board requires one: then `422 status_reason_required`) | `moderate` |
| `POST /api/v1/ideas/{id}/tags` | `{"tag_id": 3}` | `moderate` |
| `DELETE /api/v1/ideas/{id}/tags/{tagId}` | — | `moderate` |
| `POST /api/v1/ideas/{id}/merge` | `{"target_id": 7}` | `moderate` |
| `POST /api/v1/ideas/{id}/unmerge` | — | `moderate` |
| `GET /api/v1/board/branding` | — | `read` |
| `PATCH /api/v1/board/branding` | partial JSON with the fields of the app's branding and Pro settings (`primary_color`, `intro`, `hide_badge`, `theme_mode`, `visibility`, `typography_preset`, …); omitted fields keep their value | `manage` |
| `POST /api/v1/board/assets/{kind}` | multipart, file in `image` (PNG, JPEG or WebP); `kind` is `cover`, `favicon`, `og`, `logo`, `wordmark` or `wordmark_light` | `manage` |
| `DELETE /api/v1/board/assets/{kind}` | — | `manage` |

Branding reads return the same view as the app (`branding`, `pro_branding`, `assets`). Writes
use the app's validation, plan gates and audit trail; an invalid color returns `422
validation_error` with the field errors, a plan-locked field `422 plan_locked_field`, a frozen
board `423 board_frozen`. The API never accepts the upload terms: an image upload returns
`403 upload_terms_required` until the account accepted them in the app. Writes spend the
`apitoken:write` budget.

**Vote semantics.** The vote belongs to the token, not to the user who issued it: each
token is one voter with at most one vote per idea, kept separate from that user's own vote
in the app (two tokens are two voters). `value` is the desired end state — `up`/`down`
cast or change the vote, `none` removes it, repeating a value changes nothing. The
response is the idea's new tally: `{"score", "my_vote", "up_count", "down_count"}`.
Pending and merged ideas are `404`; a status closed for votes, a frozen board or a closed
voting window (`voting_not_open_yet` / `voting_closed`, binding every token) is `423`.
Votes stay counted when the token is revoked.

**Comments** are attributed to the user who issued the token (like created ideas) and
follow the app's rules, including the rejection of a second consecutive comment by the
same user.

**Status** takes a key of the board's registry (`GET /api/v1/board`), including custom
statuses; unknown keys are `422 invalid_status`. **Merge** folds idea `{id}` into
`target_id` (both published, same board, neither already merged); votes are combined with
one vote per voter. Tag and merge endpoints are idempotent where the app's are.

```bash
curl -s https://<your-install>/api/v1/ideas/42/vote \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"value": "up"}'
```

## Errors

Standard HTTP status codes: `401` (missing/invalid token), `403` (token valid but not
authorized for the requested board/action, or Agent API not available on the current
plan), `404` (resource not found or not on this token's board — the API deliberately does
not distinguish "doesn't exist" from "exists on another board", to avoid leaking
cross-board existence), `422` (validation failure), `423` (the board is not accepting this write right now: frozen,
submission/voting window closed or status closed for votes), `429` (rate limit exceeded). Error bodies are JSON
(`{"error": {"key": "…", "message": "…"}}`, see [`openapi.yaml`](openapi.yaml)); treat
the HTTP status code and `error.key` as authoritative, not the message text.

## Other trust boundaries

One more authenticated surface exists outside this Agent API, documented for completeness
but not part of the "external integration" contract above:

- **Operator panel** (`/operator/*`) — platform-wide, session-authenticated, requires the
  `operator` authorization level (`users.is_operator`, settable only via direct database
  access, no signup path grants it). Relevant only to whoever runs the installation, not
  to external integrators.

Extensions registered in `config.php` may add routes of their own (with their own
authentication); those are documented by the extension, not here.

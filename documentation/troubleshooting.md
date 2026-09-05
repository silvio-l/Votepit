# Troubleshooting

## Sign-in / magic links

**Magic-link emails never arrive.** Verify SMTP end-to-end with
`php bin/send-test-mail.php you@example.com` (see [`operations.md`](operations.md#mail-testing))
using the exact same mailer path as real sign-in. If the test mail fails, the problem is
your `smtp.*` config, not the app. Check spam folders and SPF/DKIM/DMARC on your sending
domain if the test mail succeeds but real recipients don't see it.

**"Too many attempts" / sign-in rate limited.** The `magiclink:email` (3/hour) and
`magiclink:ip` (5/hour) buckets in the `rate_limits` table are working as intended. During
authorized manual testing you can clear a specific bucket:
```sql
DELETE FROM rate_limits WHERE bucket LIKE '%magiclink%';
```
Never do this to bypass limits on real traffic.

**A user I expect to be admin isn't.** `admin_emails` in `config.php` only grants
`is_admin` **on that user's first login** — adding an address after they've already
signed in has no retroactive effect. Grant admin/owner status via the account's
membership/roles UI instead, or a direct database update for the very first bootstrap.

## Installation

**Fresh install is missing tables (`accounts`, `invites`, `api_tokens`, …) after running
`mysql < db/schema.sql`.** Expected — `db/schema.sql` is only the pre-tenancy baseline. Run
the migration runner (`php bin/migrate.php`) to bring the schema up to date; see
[`installation.md`](installation.md#3-create-the-database-schema).

**Boot-time HTTP 500 with a message about `routing_mode`/SPA capability.**
`Votepit\SpaCapabilities` refuses to boot if `config.php` sets `routing_mode: 'cloud'`
while the built SPA doesn't have account-prefixed client routes. Self-host installs should
never set `routing_mode: 'cloud'` — leave it at the default `'self-host'`. If you do run a
Cloud-style deployment, make sure the SPA build you deployed matches the routing mode you
configured.

**`app_key` or `identity_server_key` left empty.** These are required secrets, not
optional toggles — leaving either blank is a misconfiguration. Generate both with
`php -r "echo bin2hex(random_bytes(32));"` (run separately, they must differ) before going
to production.

## Duplicate detection

**Duplicate search returns no/weak results.** If your MySQL/MariaDB version or table
engine doesn't support InnoDB FULLTEXT, the app falls back to a pure-PHP comparison
automatically — this is slower and may feel less precise on large boards, but is not an
error state. Confirm your `ideas` table's storage engine is InnoDB with a FULLTEXT index on
`title` if you expect the FULLTEXT-backed path.

## Agent API / MCP

**401/403 from `/api/v1/*` or `/api/v1/mcp`.** Confirm the `Authorization: Bearer <token>`
header is present and the token hasn't been revoked. A token bound to one board will
**always** get 401/403/404 against another board's data — this is by design, not a bug (see
[`api-reference.md`](api-reference.md#authentication)).

**Token creation fails / not offered in the UI.** Agent API token creation is gated by the
injected `PlanPolicy::agentApiAllowed()` (see [`architecture.md`](architecture.md)); Community's
own default, `UnrestrictedPlanPolicy`, always allows it, so this only applies if a privately
hosted extension plugs in a restrictive policy.

**429 from the Agent API or MCP endpoint.** REST and MCP usage on one token share the same
`apitoken:read`/`apitoken:write` buckets (see [`mcp-server.md`](mcp-server.md#tools)) — a
burst of MCP tool calls can exhaust the budget a REST integration also depends on, and vice
versa.

**MCP client gets a JSON-RPC error instead of a tool result.** Check the `error.code`:
`-32700` malformed JSON, `-32600` invalid request or an attempted batch call (unsupported),
`-32601` unknown method/tool, `-32602` bad tool arguments, `-32000` rate limited. Every
response — success or error — is HTTP 200; don't rely on the HTTP status code to detect a
JSON-RPC-level failure.

## General

**Something in this documentation contradicts the code.** The code is the source of truth
— please report the mismatch (see `SECURITY.md`/repository contribution info) rather than
trusting the prose over `src/`.

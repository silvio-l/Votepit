# Embeddable widget

Votepit can show a board as a feedback widget on your own website: a
launcher button opens a panel with the board's ideas, and users who are
signed in to *your* app can vote, comment and submit ideas without a
separate Votepit login. Design record: ADR 0005.

## Setup

1. The board must be **public** or **unlisted** (private boards are never
   embeddable).
2. Board admin → **Embeddable widget**:
   - **Allowed origins**: one `https://` origin per line (scheme + host +
     optional port, no path, no wildcard, at most 10). An empty list switches
     the widget off.
   - **Identify secret**: generate it once and store it on your server
     (e.g. `VOTEPIT_WIDGET_SECRET`). It is shown exactly once. Rotating it
     invalidates every open widget session immediately.
3. Paste the embed code into your site's layout:

```html
<script src="https://votepit.example.com/widget.js" data-board="acme/ideas" async></script>
<script>
  window.Votepit = window.Votepit || function () { (window.Votepit.q = window.Votepit.q || []).push(arguments) }
  // Signed-in users only — a function is called when the panel is first opened:
  Votepit('identify', () => fetch('/votepit-jwt').then((r) => r.text()))
</script>
```

`data-board` is `<board>` on a self-hosted install and `<account>/<board>`
in cloud mode. Further commands: `Votepit('logout')`, `Votepit('open')`,
`Votepit('close')`. Optional `data-label` sets the launcher text.

The admin section also offers a copy-ready prompt for an AI coding agent and
shows the last allowed origin that loaded the widget (install detection).

In cloud mode the widget is a plan feature (`PlanPolicy::widgetAllowed`).
After a downgrade the widget page answers 404, the settings are kept and
come back with an upgrade. Self-hosted installations are unrestricted.

## Identify JWT

Your server signs a JWT per signed-in user with the board's secret:

| Part | Requirement |
|---|---|
| header | `{"alg":"HS256","typ":"JWT"}` — any other `alg` (incl. `none`) is rejected |
| `aud` | `votepit:board:<board id>` — shown in the widget settings |
| `email` | required, the user's verified e-mail address |
| `name` | optional display name, ≤ 100 characters |
| `sub` | optional stable user id, ≤ 255 characters |
| `iat`, `exp` | required integers; `exp − iat` ≤ 600 s; 60 s clock skew tolerated |

The iframe sends it as `Authorization: Bearer <jwt>` to
`POST /{account}/{board}/widget/identify` (per-IP rate limit
`widget:identify`). On success the response carries a **widget token**
(`expires_in` 3600 s) that the iframe keeps in memory only. Votepit stores the
e-mail address only as an HMAC (ADR 0002); the same address maps to the same
global Votepit user.

## Security model

- **Framing.** Only `GET /{account}/{board}/widget` is framable: its CSP is
  `frame-ancestors <allowed origins>` and it sends no `X-Frame-Options`.
  Every other response keeps `frame-ancestors 'none'` + `X-Frame-Options:
  DENY`. The relaxation is produced only by `WidgetPageAction` through an
  internal marker header that `SecurityHeaderMiddleware` re-validates
  (fail-secure to `'none'`) and strips.
- **Apache (`public/.htaccess`).** The static fallback headers in
  `.htaccess` use `Header always set`, which would override the PHP
  response. They are therefore conditional (`expr=`): skipped only for a
  successful (`200`) `GET` request line matching `/[account/]board/widget`.
  Any error status, other method or other path still gets the static DENY
  headers. `widget.js` is served with `Cross-Origin-Resource-Policy:
  cross-origin`. If you run another web server, replicate this: never add
  `X-Frame-Options`/`frame-ancestors` on the widget route, keep them
  everywhere else.
- **No cookies, no CSRF.** The iframe sends every request with
  `credentials: 'omit'`; widget responses never set cookies. Widget write
  routes (`/widget/ideas`, `…/vote`, `…/comments`) authenticate solely by the
  Bearer widget token, which is bound to account, board, user and the
  current secret. Admin/operator/support privileges are never granted
  through a widget token, and a blocked user is refused on every method.
- **postMessage.** The loader posts with an explicit target origin and
  accepts messages only from its iframe's window on the Votepit origin. The
  iframe accepts messages only from its direct parent on an allowed origin
  and replies to that exact origin.
- **Unidentified visitors** can only read; voting, commenting and
  submitting open the full board in a new tab (`noopener,noreferrer`).
- **Sandbox.** The iframe runs with `allow-scripts allow-same-origin
  allow-forms allow-popups allow-popups-to-escape-sandbox`.

**Your responsibility:** the JWT asserts the user's e-mail address. Mint it
only for users whose address you have verified, never expose the secret to
the browser, and keep the lifetime short. A token can be replayed until it
expires (≤ 10 minutes).

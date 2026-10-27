/**
 * Install helpers for the embeddable widget (ADR 0005): the HTML snippet a
 * board owner pastes into their site and a copy-ready prompt for an AI
 * coding agent that wires up the signed identify on the customer's server.
 *
 * Pure functions, no React — unit-testable without rendering the admin
 * page. The prompt is intentionally English (it targets an AI coding agent).
 * The widget secret is NEVER embedded: the prompt tells the agent to read it
 * from a server-side environment variable, so the text is safe to paste
 * into any tool.
 *
 * i18n: exempt by design, same as agentPrompts.ts. The prompt is copied into
 * an AI tool, not shown in the UI. Do not translate.
 */

export interface WidgetInstallOptions {
  /** Origin of this Votepit installation, e.g. "https://votes.example.com" (no trailing slash). */
  origin: string
  /** Path of the board without leading slash, e.g. "acme/ideas" (cloud) or "ideas" (self-host). */
  boardPath: string
  /** Required JWT `aud` claim for this board (from the settings endpoint). */
  audience: string
  /** Maximum allowed `exp - iat` in seconds (from the settings endpoint). */
  maxLifetime: number
}

/** Name of the server-side environment variable the prompt tells the agent to use. */
export const WIDGET_SECRET_ENV = 'VOTEPIT_WIDGET_SECRET'

/** The `<script>` snippet: loader + optional identify call. */
export function buildWidgetSnippet({ origin, boardPath }: WidgetInstallOptions): string {
  return [
    `<script src="${origin}/widget.js" data-board="${boardPath}" async></script>`,
    '<script>',
    '  window.Votepit = window.Votepit || function () { (window.Votepit.q = window.Votepit.q || []).push(arguments) }',
    '  // Optional, for signed-in users: a short-lived JWT minted by YOUR server (see the agent prompt).',
    "  // Votepit('identify', '<jwt>')",
    '</script>',
  ].join('\n')
}

/** A copy-ready prompt for an AI coding agent working in the customer's codebase. */
export function buildWidgetAgentPrompt(opts: WidgetInstallOptions): string {
  const minutes = Math.floor(opts.maxLifetime / 60)
  return [
    'Embed my Votepit feedback widget in this web app and identify signed-in users.',
    '',
    '1. Add this snippet to the HTML layout that every page uses (before </body>):',
    '',
    buildWidgetSnippet(opts),
    '',
    `2. Server side: add an authenticated endpoint that returns an HS256 JWT for the current user, signed with the secret stored in the environment variable ${WIDGET_SECRET_ENV} (never ship the secret to the browser). Claims:`,
    `   - aud: "${opts.audience}" (exactly this string)`,
    "   - email: the user's verified e-mail address (required)",
    '   - name: display name (optional, max 100 characters)',
    '   - sub: your stable user id (optional)',
    `   - iat: now (seconds), exp: iat + at most ${opts.maxLifetime} seconds (${minutes} minutes)`,
    '   Use the header {"alg":"HS256","typ":"JWT"} — other algorithms are rejected.',
    '',
    "3. Client side: for a signed-in user call window.Votepit('identify', () => fetch('<your JWT endpoint>').then((r) => r.text())) — the function runs when the widget is first opened, so the short-lived JWT is fresh. Call window.Votepit('logout') when they sign out.",
    '',
    `4. Make sure this site is served over https and its origin is listed under "Allowed origins" in the Votepit widget settings, otherwise the widget will not load. Do not log or persist the JWT.`,
  ].join('\n')
}

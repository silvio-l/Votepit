/**
 * Server-declared capabilities of this installation, read once from
 * GET /api/bootstrap (`features`) on SPA mount — see App.tsx. Module-level
 * state like edition.ts/accountContext.ts: pages read it synchronously
 * instead of threading it through props.
 *
 * `board_smtp`   — per-board SMTP settings exist (self-host); false on a
 *                  hosted multi-tenant install where the operator's central
 *                  mailer is the only outbound path.
 * `legal_links`  — footer links (Terms, Privacy, …) per language, or null.
 *                  A self-hosted installation has none: the operator's legal
 *                  obligations are their own, so core ships no links. A
 *                  hosted service supplies them through its extension.
 * `marketing_discover_url` — external URL DiscoverPage redirects to instead
 *                  of rendering the in-app list, or null. A hosted service
 *                  with its own marketing-styled discovery page supplies
 *                  this through its extension (env-specific, so it never
 *                  lives as a literal in core).
 * `turnstile_site_key` — Cloudflare Turnstile public site key, or null when
 *                  the anti-automation challenge isn't configured (default —
 *                  see Config::turnstileConfigured()). Only the public site
 *                  key is safe to expose here; the secret key never leaves
 *                  the server. Most requests never need this: the challenge
 *                  only engages once the requester's IP has repeatedly
 *                  tripped the existing rate limits (see TurnstileGuard).
 * `vote_reasons`  — reasons for votes offered (installation kill switch). `false`
 *                  hides the board toggle and the reason follow-up; the server
 *                  refuses reasons regardless.
 * `marketing_consent` — product-news / promotion consent offered (signup
 *                  checkbox, profile toggle, confirm page). Only a hosted
 *                  installation with the marketing consent enabled reports
 *                  true; self-host and older servers report false/absent.
 * Any further key is an extension-defined flag (e.g. `billing`) that the
 * extension's own pages read via `getFeatures()`.
 */

import type { Language } from './i18n/context'

export interface LegalLink {
  label: string
  href: string
}

export interface Features {
  board_smtp: boolean
  legal_links: Partial<Record<Language, LegalLink[]>> | null
  marketing_discover_url: string | null
  turnstile_site_key: string | null
  marketing_consent: boolean
  vote_reasons: boolean
  [extensionFlag: string]: unknown
}

/** Community defaults — what a bare core install reports. */
const DEFAULT_FEATURES: Features = {
  board_smtp: true,
  legal_links: null,
  marketing_discover_url: null,
  turnstile_site_key: null,
  marketing_consent: false,
  vote_reasons: true,
}

let currentFeatures: Features = DEFAULT_FEATURES

export function setFeatures(features: Features | undefined): void {
  currentFeatures = features ?? DEFAULT_FEATURES
}

export function getFeatures(): Features {
  return currentFeatures
}

/**
 * Mirrors `bootstrap()`'s top-level `translation_available` flag (not part
 * of `features` — see BootstrapResponse in lib/api.ts). Set once from
 * App.tsx's mount-time bootstrap() call; read by any admin surface that
 * needs to know whether offering the per-board translation toggle makes
 * sense at all (Config::translationConfigured() fail-secure false by
 * default — a self-host without Google credentials configured has nothing
 * to toggle).
 */
let translationAvailable = false

export function setTranslationAvailable(value: boolean): void {
  translationAvailable = value
}

export function getTranslationAvailable(): boolean {
  return translationAvailable
}

/** Footer legal links for the given language (falls back to English), or [] when none are configured. */
export function legalLinksFor(language: Language): LegalLink[] {
  // A bootstrap payload may omit `legal_links` entirely (older servers,
  // partial feature maps) — treat missing like "none configured".
  const links = currentFeatures.legal_links
  if (links === null || links === undefined) return []
  return links[language] ?? links.en ?? []
}

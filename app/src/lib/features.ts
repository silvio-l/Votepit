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
  [extensionFlag: string]: unknown
}

/** Community defaults — what a bare core install reports. */
const DEFAULT_FEATURES: Features = {
  board_smtp: true,
  legal_links: null,
  marketing_discover_url: null,
}

let currentFeatures: Features = DEFAULT_FEATURES

export function setFeatures(features: Features | undefined): void {
  currentFeatures = features ?? DEFAULT_FEATURES
}

export function getFeatures(): Features {
  return currentFeatures
}

/** Footer legal links for the given language (falls back to English), or [] when none are configured. */
export function legalLinksFor(language: Language): LegalLink[] {
  // A bootstrap payload may omit `legal_links` entirely (older servers,
  // partial feature maps) — treat missing like "none configured".
  const links = currentFeatures.legal_links
  if (links === null || links === undefined) return []
  return links[language] ?? links.en ?? []
}

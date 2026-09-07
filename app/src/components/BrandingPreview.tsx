/**
 * BrandingPreview — small live preview tile for a board's brand tokens
 * (primary color, secondary color, logo) inside the branding form
 * (AdminPage.tsx). Updates on every keystroke, BEFORE saving — purely a
 * client-side mock-up, it never calls the API.
 *
 * Mirrors the server's validation rules (core/src/Security/
 * BrandingValidator.php) so the preview only ever renders a value that
 * would actually be accepted: an invalid hex falls back to the platform
 * default color, an unsafe/invalid logo URL falls back to the initials
 * placeholder. Colors are set as individual CSS custom-property/background
 * values via React's `style` prop (never `dangerouslySetInnerHTML`, never a
 * raw interpolated style string) — React sets each property directly on
 * CSSStyleDeclaration, so a rejected/garbage value simply fails to apply
 * rather than opening any injection surface. The logo is a plain `<img
 * src>` (React-escaped) with `onError` clearing to the same placeholder a
 * broken/unreachable URL would otherwise show as.
 */

import { useState } from 'react'
import { useT } from '../lib/i18n/context'

const HEX_COLOR_PATTERN = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

/** Platform default (tokens.css `--vp-primary`) — same fallback the server's theme uses. */
const DEFAULT_PRIMARY = '#1fa890'
/** Neutral placeholder for an unset/invalid secondary color (ink, tokens.css `--vp-ink`). */
const DEFAULT_SECONDARY = '#15161a'

function sanitizedColor(value: string, fallback: string): string {
  const trimmed = value.trim()
  return HEX_COLOR_PATTERN.test(trimmed) ? trimmed : fallback
}

/** Mirrors BrandingValidator::logoUrl()'s scheme allowlist (relative path or https). */
function isSafeLogoUrl(value: string): boolean {
  const trimmed = value.trim()
  if (trimmed === '' || /\s/.test(trimmed) || trimmed.includes('\\')) return false
  if (trimmed.startsWith('/')) return !trimmed.startsWith('//')
  return /^https:\/\//i.test(trimmed)
}

interface BrandingPreviewProps {
  boardName: string
  primaryColor: string
  secondaryColor: string
  logoUrl: string
}

export function BrandingPreview({
  boardName,
  primaryColor,
  secondaryColor,
  logoUrl,
}: BrandingPreviewProps) {
  const t = useT('adminPage')
  const [logoFailed, setLogoFailed] = useState(false)
  const [lastLogoUrl, setLastLogoUrl] = useState(logoUrl)

  // A newly typed/changed URL deserves a fresh load attempt, even if a
  // previous one failed — adjust state during render (React's recommended
  // pattern for "reset on prop change") instead of an effect, so there is
  // no extra render/flash of the stale failed state.
  if (logoUrl !== lastLogoUrl) {
    setLastLogoUrl(logoUrl)
    setLogoFailed(false)
  }

  const primary = sanitizedColor(primaryColor, DEFAULT_PRIMARY)
  const secondary = sanitizedColor(secondaryColor, DEFAULT_SECONDARY)
  const showLogo = isSafeLogoUrl(logoUrl) && !logoFailed
  const displayName = boardName.trim() !== '' ? boardName : t('brandingPreviewFallbackName')
  const initial = displayName.trim().charAt(0).toUpperCase() || '?'

  return (
    <div>
      <p
        id="branding-preview-label"
        className="mb-2 text-vp-xs font-semibold uppercase tracking-wide text-vp-text-muted"
      >
        {t('brandingPreviewLabel')}
      </p>
      <div
        role="group"
        aria-labelledby="branding-preview-label"
        className="overflow-hidden rounded-vp-md border border-vp-border-subtle bg-vp-surface"
      >
        <div style={{ backgroundColor: primary }} className="h-2" />
        <div className="flex items-center gap-3 px-4 py-3">
          {showLogo ? (
            <img
              src={logoUrl.trim()}
              alt=""
              onError={() => setLogoFailed(true)}
              className="size-9 shrink-0 rounded-vp-sm object-contain bg-vp-surface-frost"
            />
          ) : (
            <span
              aria-hidden="true"
              style={{ backgroundColor: secondary }}
              className="flex size-9 shrink-0 items-center justify-center rounded-vp-sm text-vp-sm font-semibold text-white"
            >
              {initial}
            </span>
          )}
          <span className="min-w-0 truncate text-vp-sm font-semibold text-vp-ink">
            {displayName}
          </span>
        </div>
        <div className="flex items-center gap-2 px-4 pb-3">
          <span
            style={{ borderColor: primary, color: primary }}
            className="rounded-vp-sm border px-2 py-0.5 text-vp-xs font-medium"
          >
            {t('brandingPreviewVoteSample')}
          </span>
          <span
            style={{ backgroundColor: secondary }}
            className="rounded-vp-sm px-2 py-0.5 text-vp-xs font-medium text-white"
          >
            {t('brandingPreviewSecondarySwatchTitle')}
          </span>
        </div>
      </div>
    </div>
  )
}

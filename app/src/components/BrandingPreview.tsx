/**
 * BrandingPreview — the board canvas of the branding builder (P-27) and the
 * live preview in the onboarding look step. It is a client-side mock-up: it never
 * calls the API and shows unsaved values immediately.
 *
 * Same rendering path as the real board: the root takes `brandingStyle()` from
 * lib/branding.ts, the Pro presets switch on the same `data-board-*` attributes
 * the board wrapper uses, and the elements are the DS components/classes the
 * board uses. Only plan-allowed values reach that rendering (Q19: a locked value
 * is kept but not applied) unless `applyLocked` is set, which previews what a
 * higher plan would show. Nothing from this component is saved.
 *
 * Areas: elements carry `data-builder-area`. Hovering outlines the area and shows
 * its label; clicking reports it through `onAreaFocus`, so the builder can focus
 * the matching setting. The keyboard path is the builder's area list.
 */

import { VotepitMark } from '@votepit/ui'
import {
  accessibleFill,
  Badge,
  BoardCover,
  Button,
  COVER_PATTERNS,
  type CoverPattern,
  InlineNotice,
  StatusChip,
  statusChipFor,
  VoteControl,
  type VoteControlLabels,
} from '@votepit/ui/ds'
import { type MouseEvent, type ReactNode, useState } from 'react'
import { useDsLabels } from '../hooks/useDsLabels'
import { useIdeaCardLabels } from '../hooks/useIdeaCardLabels'
import type { LogoPlateChoice } from '../lib/api/boardAdmin'
import type { BoardThemeMode } from '../lib/api/model'
import {
  boardCanvasFor,
  brandingStyle,
  brandThemeVars,
  coverAccentFor,
  type DensityPreset,
  type ShapeStyle,
  type TypographyPreset,
  topbarBrandVars,
} from '../lib/branding'
import { chooseTopbarWordmark, topbarBarColor } from '../lib/brandPlate'
import type { RequiredPlan } from '../lib/entitlements'
import { type Language, LanguageScope, useT } from '../lib/i18n/context'
import { type ResolvedTheme, useResolvedTheme } from '../lib/theme'
import { PlanUpgradeLink } from './PlanUpgradeLink'

const HEX_COLOR_PATTERN = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

/** Platform default (tokens.css `--vp-primary`) — same fallback the server's theme uses. */
const DEFAULT_PRIMARY = '#1fa890'
/** Neutral placeholder for an unset/invalid secondary color (ink, tokens.css `--vp-ink`). */
const DEFAULT_SECONDARY = '#15161a'
/** Idea rows the board preview lists (the count shown in its stat line). */
const PREVIEW_IDEA_COUNT = 2

export type BuilderPage = 'board' | 'idea' | 'submit' | 'roadmap' | 'share' | 'widget' | 'mail'
export type BuilderDevice = 'desktop' | 'tablet' | 'phone'
/** Page state: the empty board or the submit form's thank-you notice (wording preview). */
export type BuilderState = 'default' | 'empty' | 'thanks'
export type BuilderArea =
  | 'topbar'
  | 'cover'
  | 'logo'
  | 'intro'
  | 'votes'
  | 'badge'
  | 'widget'
  | 'mail'
  | 'roadmap'
  | 'footer'
  | 'favicon'
  | 'share'
  | 'wording'

/**
 * Pro plan gates the preview honours. `allowed: false` keeps the value out of the
 * canvas (shown with its tier badge) unless `applyLocked` is set.
 */
export type PreviewGateKey =
  | 'presets'
  | 'wording'
  | 'pattern'
  | 'cover'
  | 'links'
  | 'roadmap'
  | 'images'
export interface PreviewGate {
  allowed: boolean
  requiredPlan: RequiredPlan | null
}

/** Owner wording of the locale being edited; a missing or blank field keeps Votepit's text. */
export interface BrandingPreviewWording {
  idea_singular?: string
  idea_plural?: string
  submit_button?: string
  empty_state?: string
  thank_you?: string
}

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

/** The board's back link is shown for https targets only. */
function isHttpsUrl(value: string): boolean {
  return /^https:\/\/\S+$/i.test(value.trim())
}

function isCoverPattern(value: string | null): value is CoverPattern {
  return value !== null && (COVER_PATTERNS as readonly string[]).includes(value)
}

/** A preview value kept out of the canvas by its plan: named by its tier, never applied silently. */
function LockedNote({
  testId,
  tier,
  hint,
  requiredPlan,
  linkLabel,
}: {
  testId: string
  tier: string
  hint: string
  requiredPlan: RequiredPlan | null
  linkLabel: string
}) {
  return (
    <p
      data-testid={testId}
      className="m-0 flex flex-wrap items-center gap-x-2 border-t border-vp-rule px-4 py-3 text-vp-xs text-vp-text-muted"
    >
      <Badge tone="neutral">{tier}</Badge>
      <span>{hint}</span>
      <PlanUpgradeLink label={linkLabel} requiredPlan={requiredPlan} />
    </p>
  )
}

export interface BrandingPreviewProps {
  boardName: string
  /**
   * Language of the preview's texts while a per-locale text is edited (the wording locale).
   * Omitted: the page language applies.
   */
  previewLanguage?: Language
  primaryColor: string
  secondaryColor: string
  /** Highlight colour and per-mode values (v0.1.5); empty = not set. */
  accentColor?: string
  primaryColorDark?: string
  accentColorDark?: string
  logoPlate?: LogoPlateChoice | null
  logoRingHidden?: boolean
  accentAllowed?: boolean
  perModeAllowed?: boolean
  logoPlateAllowed?: boolean
  logoUrl: string
  /** Topbar wordmark to show on the bar (pending or stored), or null for the mark. */
  wordmarkSrc?: string | null
  /** Contrast metadata of the wordmark; a light plate is shown when the bar needs one. */
  wordmarkInk?: number | null
  /** Optional light wordmark for dark bars (phase 4), pending or stored, or null. */
  wordmarkLightSrc?: string | null
  wordmarkLightInk?: number | null
  /** Mean ink of the logo (board_assets.ink_luminance): the auto plate checks it on the bar. */
  logoInk?: number | null
  /** False on a plan without the secondary colour: kept, not applied, named by its tier. */
  secondaryAllowed: boolean
  secondaryRequiredPlan?: RequiredPlan | null
  logoAllowed?: boolean
  logoRequiredPlan?: RequiredPlan | null
  introText?: string
  introAllowed?: boolean
  hideBadge?: boolean
  badgeHideAllowed?: boolean
  /** Page shown on the canvas (builder page switch). */
  page?: BuilderPage
  /** Frame width (builder device toggle). */
  device?: BuilderDevice
  /** Area selected in the panel: drawn with a solid outline. */
  selectedArea?: BuilderArea | null
  /** Preview only: apply the plan-locked values as a higher plan would. */
  applyLocked?: boolean
  /** Compact look-step variant: no device frame chrome, no area labels. */
  compact?: boolean
  /** Area clicked on the canvas. */
  onAreaFocus?: (area: BuilderArea) => void
  /** Stored cover image (board cover upload); the default cover art is shown without it. */
  coverSrc?: string | null
  /** Labels of the header links that are shown in the top bar. */
  headerLinkLabels?: string[]
  /** Custom vote words (Pro wording) shown beside the vote controls. */
  voteWords?: { up: string; down: string } | null
  /** Pro typography, shape and density presets (applied through the board's data attributes). */
  typography?: TypographyPreset
  shape?: ShapeStyle
  density?: DensityPreset
  /** Selected cover pattern, used when no cover image is shown. */
  coverPattern?: string | null
  /** P-26 display mode being edited; the preview shows that scheme (system = the viewer's). */
  themeMode?: BoardThemeMode
  /** Owner wording of the edited locale. */
  wording?: BrandingPreviewWording | null
  /** Back link of the board (label and https target) shown in the footer. */
  backLink?: { label: string; url: string } | null
  /** Footer note of the board. */
  footerNote?: string
  /** The board hides its roadmap from visitors. */
  roadmapHidden?: boolean
  /** Browser-tab icon and share image (favicon and og variants). */
  faviconSrc?: string | null
  ogSrc?: string | null
  /** Plan gates per feature; a missing entry counts as allowed without a tier. */
  gates?: Partial<Record<PreviewGateKey, Partial<PreviewGate>>>
  /** Page state for the board (empty) and submit (thank-you) previews. */
  state?: BuilderState
}

export function BrandingPreview({ previewLanguage, ...props }: BrandingPreviewProps) {
  return (
    <LanguageScope language={previewLanguage}>
      <BrandingPreviewBody {...props} />
    </LanguageScope>
  )
}

function BrandingPreviewBody({
  boardName,
  primaryColor,
  secondaryColor,
  accentColor = '',
  primaryColorDark = '',
  accentColorDark = '',
  logoPlate = null,
  logoRingHidden = false,
  accentAllowed = false,
  perModeAllowed = false,
  logoPlateAllowed = false,
  logoUrl,
  wordmarkSrc = null,
  wordmarkInk = null,
  wordmarkLightSrc = null,
  wordmarkLightInk = null,
  logoInk = null,
  secondaryAllowed,
  secondaryRequiredPlan = 'starter',
  logoAllowed = true,
  logoRequiredPlan = 'starter',
  introText = '',
  introAllowed = true,
  hideBadge = false,
  badgeHideAllowed = true,
  page = 'board',
  device = 'desktop',
  selectedArea = null,
  applyLocked = false,
  compact = false,
  onAreaFocus,
  coverSrc = null,
  headerLinkLabels = [],
  voteWords = null,
  typography = 'default',
  shape = 'rounded',
  density = 'comfortable',
  coverPattern = null,
  themeMode = 'system',
  wording = null,
  backLink = null,
  footerNote = '',
  roadmapHidden = false,
  faviconSrc = null,
  ogSrc = null,
  gates = {},
  state = 'default',
}: BrandingPreviewProps) {
  const resolvedTheme = useResolvedTheme()
  const t = useT('adminPage')
  const tCommon = useT('common')
  const tBoard = useT('boardPage')
  const tSubmit = useT('submitPage')
  const cardLabels = useIdeaCardLabels(null)
  const dsLabels = useDsLabels()
  const previewVoteLabels: VoteControlLabels =
    voteWords === null
      ? cardLabels.voteLabels
      : {
          ...cardLabels.voteLabels,
          up: { ...cardLabels.voteLabels.up, label: voteWords.up },
          down: { ...cardLabels.voteLabels.down, label: voteWords.down },
        }
  const [logoFailed, setLogoFailed] = useState(false)
  const [lastLogoUrl, setLastLogoUrl] = useState(logoUrl)
  const [hoverArea, setHoverArea] = useState<BuilderArea | null>(null)

  // A newly typed/changed URL deserves a fresh load attempt, even if a
  // previous one failed — adjust state during render instead of an effect.
  if (logoUrl !== lastLogoUrl) {
    setLastLogoUrl(logoUrl)
    setLogoFailed(false)
  }

  // A gate applies when its plan allows the feature, or when the preview shows the locked values.
  const gateOn = (key: PreviewGateKey) => applyLocked || (gates[key]?.allowed ?? true)
  const requiredOf = (key: PreviewGateKey): RequiredPlan | null => gates[key]?.requiredPlan ?? null
  const presetsOn = gateOn('presets')
  const wordingOn = gateOn('wording')
  const patternOn = gateOn('pattern')
  const coverOn = gateOn('cover')
  const linksOn = gateOn('links')
  const roadmapOn = gateOn('roadmap')
  const imagesOn = gateOn('images')

  const primary = sanitizedColor(primaryColor, DEFAULT_PRIMARY)
  const secondary = sanitizedColor(secondaryColor, DEFAULT_SECONDARY)
  const showSecondary =
    (secondaryAllowed || applyLocked) && HEX_COLOR_PATTERN.test(secondaryColor.trim())
  const showLogo = (logoAllowed || applyLocked) && isSafeLogoUrl(logoUrl) && !logoFailed
  const showIntro = (introAllowed || applyLocked) && introText.trim() !== ''
  const hideApplied = hideBadge && (badgeHideAllowed || applyLocked)
  const roadmapHiddenApplied = roadmapHidden && roadmapOn
  const showCover = coverSrc !== null && coverOn
  const pattern = patternOn && isCoverPattern(coverPattern) ? coverPattern : null
  const showOgImage = ogSrc !== null && imagesOn
  // A value shows when its plan gate is open (or the preview shows the locked values), like the secondary colour.
  const colorOn = (allowed: boolean, value: string): string | null =>
    (allowed || applyLocked) && HEX_COLOR_PATTERN.test(value.trim()) ? value.trim() : null
  const plateOn = logoPlateAllowed || applyLocked
  // The board as the voters get it: the same function the real board uses.
  const appliedBoard = {
    primary_color: primary,
    secondary_color: showSecondary ? secondaryColor.trim() : null,
    accent_color: colorOn(accentAllowed, accentColor),
    primary_color_dark: colorOn(perModeAllowed, primaryColorDark),
    accent_color_dark: colorOn(perModeAllowed, accentColorDark),
    logo_plate: plateOn ? logoPlate : null,
    logo_ring_hidden: plateOn && logoRingHidden,
    asset_ink: logoInk === null ? null : { logo: logoInk },
  }
  // The chosen display mode decides the preview's scheme; "system" keeps the viewer's.
  const previewTheme: ResolvedTheme =
    themeMode === 'light' || themeMode === 'dark' ? themeMode : resolvedTheme
  const rootVars = brandingStyle(appliedBoard, previewTheme)
  const barVars = topbarBrandVars(
    appliedBoard.primary_color,
    appliedBoard.secondary_color,
    appliedBoard.accent_color,
  )
  // A wordmark that would vanish on this bar gets the same light plate the board shows.
  // The same choice the board makes: the light variant on a dark enough bar, else the normal one.
  const topbarWordmark = chooseTopbarWordmark(
    topbarBarColor(appliedBoard.secondary_color),
    wordmarkSrc !== null ? { value: wordmarkSrc, ink: wordmarkInk } : null,
    wordmarkLightSrc !== null ? { value: wordmarkLightSrc, ink: wordmarkLightInk } : null,
  )
  const shownWordmark = topbarWordmark?.value ?? null
  const wordmarkPlated = topbarWordmark?.plated ?? false
  const { corrections } = brandThemeVars(
    appliedBoard.primary_color,
    appliedBoard.secondary_color,
    'light',
    {
      accent: appliedBoard.accent_color,
      primaryDark: appliedBoard.primary_color_dark,
      accentDark: appliedBoard.accent_color_dark,
    },
  )
  // Same attributes the board wrapper gets (brandingProAttributes); the presets' CSS lives in tokens.css.
  const presetAttributes = presetsOn
    ? {
        'data-board-typography': typography,
        'data-board-shape': shape,
        'data-board-density': density,
      }
    : {}
  const displayName = boardName.trim() !== '' ? boardName : t('brandingPreviewFallbackName')
  const initial = displayName.trim().charAt(0).toUpperCase() || '?'
  const tierLabel = (plan: RequiredPlan | null | undefined) =>
    plan === 'pro'
      ? tCommon('planAvailableFromPro')
      : plan === 'starter'
        ? tCommon('planAvailableFromStarter')
        : tCommon('planAvailableFromLite')
  const ideaTitle = t('brandingPreviewIdeaTitle')

  // Owner wording: a blank field keeps the board's own text (same fallback as the real board).
  const wordingText = (key: keyof BrandingPreviewWording, fallback: string): string => {
    const raw = wordingOn ? wording?.[key] : undefined
    return raw !== undefined && raw.trim() !== '' ? raw : fallback
  }
  const statLabel = (count: number): string => {
    const key = count === 1 ? 'idea_singular' : 'idea_plural'
    const custom = wordingText(key, '')
    return custom !== ''
      ? `${count} ${custom}`
      : tBoard(count === 1 ? 'statIdeasSingular' : 'statIdeasPlural', { count })
  }

  const areaProps = (area: BuilderArea) => ({
    'data-builder-area': area,
    'data-hover': hoverArea === area ? 'true' : undefined,
    className: `relative ${
      selectedArea === area
        ? 'outline outline-2 -outline-offset-2 outline-solid outline-vp-accent'
        : hoverArea === area
          ? 'outline outline-2 -outline-offset-2 outline-dashed outline-vp-accent'
          : ''
    }`,
  })
  const areaLabel = (area: BuilderArea) =>
    hoverArea === area && !compact ? (
      <span
        aria-hidden="true"
        className="pointer-events-none absolute right-1 top-1 z-10 rounded-vp-sm bg-vp-ink px-2 py-0.5 text-vp-xs font-medium text-vp-on-ink"
      >
        {t(`builderArea_${area}`)}
      </span>
    ) : null

  const onMouseOver = (e: MouseEvent<HTMLDivElement>) => {
    const el = (e.target as HTMLElement).closest<HTMLElement>('[data-builder-area]')
    setHoverArea((el?.dataset.builderArea as BuilderArea | undefined) ?? null)
  }
  const onClick = (e: MouseEvent<HTMLDivElement>) => {
    const el = (e.target as HTMLElement).closest<HTMLElement>('[data-builder-area]')
    const area = el?.dataset.builderArea as BuilderArea | undefined
    if (area !== undefined) onAreaFocus?.(area)
  }

  // Board and roadmap pages carry their own Ideen / Roadmap tabs under the profile
  // header (G-12), so the topbar drops its pair there, as the real pages do.
  const tabsPage = page === 'board' || page === 'roadmap'
  const profileTabs = (current: 'ideas' | 'roadmap') =>
    roadmapHiddenApplied ? null : (
      <nav
        aria-label={tCommon('header.navAriaLabel')}
        data-testid="branding-preview-profile-tabs"
        className="mx-4 mt-4 flex gap-6 border-b border-vp-border"
      >
        {(
          [
            { key: 'ideas', label: tBoard('ideasAriaLabel') },
            { key: 'roadmap', label: tCommon('header.roadmap') },
          ] as const
        ).map((item) => (
          <span
            key={item.key}
            aria-current={item.key === current ? 'page' : undefined}
            className={`inline-flex min-h-11 items-center border-b-2 px-1 text-vp-sm font-semibold ${
              item.key === current
                ? 'border-vp-ink text-vp-title'
                : 'border-transparent text-vp-text-secondary'
            }`}
          >
            {item.label}
          </span>
        ))}
      </nav>
    )

  const roadmapLink = roadmapHiddenApplied ? null : (
    <span
      {...areaProps('roadmap')}
      className={`ds-topbar__link ${areaProps('roadmap').className}`}
      data-testid="branding-preview-roadmap-link"
    >
      {areaLabel('roadmap')}
      {tCommon('header.roadmap')}
    </span>
  )

  const topbar = (
    <div
      data-builder-area="topbar"
      className="ds-topbar"
      style={{ ...barVars, position: 'relative' }}
      data-testid="branding-preview-topbar"
    >
      {areaLabel('topbar')}
      <div className="ds-topbar__inner">
        <span className="ds-topbar__brand flex min-w-0 items-center gap-2">
          {shownWordmark !== null ? (
            <span
              data-testid="branding-preview-wordmark"
              // Fixed white plate (exception, see packages/ui/DESIGN.md): the wordmark keeps its own colours on any bar.
              className={`inline-flex min-w-0 items-center ${wordmarkPlated ? 'rounded-vp-md bg-white p-1' : ''}`}
            >
              <img
                src={shownWordmark}
                alt={displayName}
                className="h-7 max-w-[12rem] object-contain object-left"
              />
            </span>
          ) : (
            <>
              <span className="ds-topbar__mark">
                <VotepitMark />
              </span>
              <span className="ds-topbar__on-bar min-w-0 truncate font-archivo text-vp-sm font-extrabold">
                {displayName}
              </span>
            </>
          )}
        </span>
        {!tabsPage && (
          <span className="ds-topbar__link" aria-current="page">
            {tCommon('header.board')}
          </span>
        )}
        {!tabsPage && roadmapLink}
        {headerLinkLabels.map((label) => (
          <span key={label} className="ds-topbar__link" data-testid="branding-preview-header-link">
            {label}
          </span>
        ))}
      </div>
    </div>
  )

  const logoEl = showLogo ? (
    <img
      src={logoUrl.trim()}
      alt=""
      onError={() => setLogoFailed(true)}
      className="-mt-6 size-12 shrink-0 rounded-full border-2 border-vp-surface object-contain bg-vp-surface-frost"
    />
  ) : (
    <span
      aria-hidden="true"
      style={{ backgroundColor: secondary, color: accessibleFill(secondary).text }}
      className="-mt-6 flex size-12 shrink-0 items-center justify-center rounded-full border-2 border-vp-surface text-vp-sm font-semibold"
    >
      {initial}
    </span>
  )

  const voteRow = (title: string, up: number, down: number) => (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-vp-rule px-4 py-3">
      <span className="min-w-0 flex-1 basis-40 text-vp-sm font-medium text-vp-title break-words hyphens-auto">
        {title}
      </span>
      <span {...areaProps('votes')}>
        {areaLabel('votes')}
        <span className="flex items-center gap-2">
          {voteWords !== null && (
            <span
              data-testid="branding-preview-vote-words"
              className="text-vp-xs text-vp-text-muted"
            >
              {voteWords.up}
            </span>
          )}
          <VoteControl
            direction="up"
            labels={previewVoteLabels}
            locale={cardLabels.locale}
            count={up}
            selected
            ideaTitle={title}
            readonly
            size="compact"
            testId="branding-preview-vote-up"
          />
          <VoteControl
            direction="down"
            labels={previewVoteLabels}
            locale={cardLabels.locale}
            count={down}
            ideaTitle={title}
            readonly
            size="compact"
            testId="branding-preview-vote-down"
          />
          {voteWords !== null && (
            <span className="text-vp-xs text-vp-text-muted">{voteWords.down}</span>
          )}
        </span>
      </span>
    </div>
  )

  const badgeEl = hideApplied ? null : (
    <p
      {...areaProps('badge')}
      className={`m-0 px-4 py-2 text-vp-xs text-vp-text-muted ${areaProps('badge').className}`}
    >
      {areaLabel('badge')}
      {t('brandingPreviewBadge')}
    </p>
  )

  // Back link and footer note (back_link_footer gate). Shown on the board's own pages only.
  const linkShown =
    linksOn && backLink !== null && backLink.label.trim() !== '' && isHttpsUrl(backLink.url)
  const noteShown = linksOn && footerNote.trim() !== ''
  const footerEl =
    page === 'board' || page === 'idea' || page === 'submit' || page === 'roadmap' ? (
      <div
        {...areaProps('footer')}
        className={`flex flex-col items-center gap-1 px-4 py-4 text-center ${areaProps('footer').className}`}
        data-testid="branding-preview-footer"
      >
        {areaLabel('footer')}
        {noteShown && <p className="m-0 text-vp-xs text-vp-text-muted">{footerNote}</p>}
        {linkShown && (
          <span className="text-vp-sm text-vp-title underline underline-offset-2">
            {backLink?.label}
          </span>
        )}
        {!noteShown && !linkShown && (
          <p className="m-0 text-vp-xs text-vp-text-muted">{t('brandingPreviewFooterEmpty')}</p>
        )}
      </div>
    ) : null

  // Shared by the board profile and the roadmap view: the cover band and the logo row.
  // The preview frame is the visible width, so the band uses the frame variant (no cqw).
  const coverArea = (
    <span {...areaProps('cover')} className={`block ${areaProps('cover').className}`}>
      {areaLabel('cover')}
      {showCover ? (
        <img
          src={coverSrc ?? undefined}
          alt=""
          data-testid="branding-preview-cover-image"
          className="ds-board-cover ds-board-cover--frame"
        />
      ) : (
        <BoardCover
          accent={coverAccentFor(appliedBoard, previewTheme === 'dark')}
          surface={appliedBoard.secondary_color ?? boardCanvasFor(appliedBoard, previewTheme)}
          dark={previewTheme === 'dark'}
          seed={displayName}
          pattern={pattern}
          className="ds-board-cover--frame"
          testId="branding-preview-cover-pattern"
        />
      )}
    </span>
  )
  const logoRow = (
    <div className="flex items-end gap-3 px-4 pb-3">
      <span {...areaProps('logo')} className={`${areaProps('logo').className} shrink-0`}>
        {areaLabel('logo')}
        {logoEl}
      </span>
      <span className="min-w-0 truncate pb-1 text-vp-sm font-semibold text-vp-title">
        {displayName}
      </span>
    </div>
  )

  let body: ReactNode
  if (page === 'idea') {
    body = (
      <div className="flex flex-col gap-3 px-4 py-4" data-testid="branding-preview-page-idea">
        <span
          {...areaProps('wording')}
          className={`text-vp-xs text-vp-text-muted ${areaProps('wording').className}`}
        >
          {areaLabel('wording')}
          {statLabel(1)}
        </span>
        <h3 className="m-0 text-vp-lg font-semibold text-vp-title">{ideaTitle}</h3>
        <span {...areaProps('votes')}>
          {areaLabel('votes')}
          <span className="flex gap-2">
            <VoteControl
              direction="up"
              labels={previewVoteLabels}
              locale={cardLabels.locale}
              count={12}
              selected
              ideaTitle={ideaTitle}
              readonly
              size="compact"
              testId="branding-preview-vote-up"
            />
            <VoteControl
              direction="down"
              labels={previewVoteLabels}
              locale={cardLabels.locale}
              count={2}
              ideaTitle={ideaTitle}
              readonly
              size="compact"
              testId="branding-preview-vote-down"
            />
          </span>
        </span>
        <p className="m-0 text-vp-sm text-vp-text-secondary">{t('brandingPreviewIdeaBody')}</p>
        <span>
          <Button type="button" testId="branding-preview-comment-button" variant="primary">
            {t('brandingPreviewCommentButton')}
          </Button>
        </span>
      </div>
    )
  } else if (page === 'submit') {
    body =
      state === 'thanks' ? (
        <div
          {...areaProps('wording')}
          className={`flex flex-col gap-3 px-4 py-4 ${areaProps('wording').className}`}
          data-testid="branding-preview-page-submit"
        >
          {areaLabel('wording')}
          <InlineNotice
            tone="success"
            testId="branding-preview-thanks"
            title={wordingText('thank_you', tSubmit('guestThanksPendingTitle'))}
          >
            {tSubmit('guestThanksPendingBody')}
          </InlineNotice>
        </div>
      ) : (
        <div className="flex flex-col gap-3 px-4 py-4" data-testid="branding-preview-page-submit">
          <span className="text-vp-sm font-medium text-vp-title">
            {t('brandingPreviewSubmitTitle')}
          </span>
          <span
            className="block h-9 rounded-vp-sm border border-vp-border-subtle bg-vp-surface"
            aria-hidden="true"
          />
          <span
            {...areaProps('wording')}
            className={`justify-self-start ${areaProps('wording').className}`}
          >
            {areaLabel('wording')}
            <Button type="button" testId="branding-preview-submit-button" variant="primary">
              {wordingText('submit_button', t('brandingPreviewSubmitButton'))}
            </Button>
          </span>
        </div>
      )
  } else if (page === 'roadmap') {
    body = roadmapHiddenApplied ? (
      <div
        {...areaProps('roadmap')}
        className={`flex flex-col gap-2 px-4 py-6 ${areaProps('roadmap').className}`}
        data-testid="branding-preview-page-roadmap"
      >
        {areaLabel('roadmap')}
        <p
          className="m-0 text-vp-sm text-vp-text-secondary"
          data-testid="branding-preview-roadmap-hidden"
        >
          {t('brandingPreviewRoadmapHidden')}
        </p>
      </div>
    ) : (
      // The real roadmap opens with the board's profile header (cover band, logo row),
      // so the preview does too; the status columns below stand in for the ideas.
      <>
        <div data-testid="branding-preview-roadmap-profile" className="relative">
          {coverArea}
          {logoRow}
          {profileTabs('roadmap')}
        </div>
        <div
          {...areaProps('roadmap')}
          className={`grid gap-3 px-4 py-4 sm:grid-cols-3 ${areaProps('roadmap').className}`}
          data-testid="branding-preview-page-roadmap"
        >
          {areaLabel('roadmap')}
          {(['planned', 'in-progress', 'done'] as const).map((status) => (
            <div key={status} className="flex flex-col gap-2">
              <StatusChip
                tone={statusChipFor(status, dsLabels.statusLabels[status]).tone}
                icon={statusChipFor(status, dsLabels.statusLabels[status]).icon}
              >
                {statusChipFor(status, dsLabels.statusLabels[status]).label}
              </StatusChip>
              <span
                className="h-8 rounded-vp-sm border border-vp-border-subtle bg-vp-surface"
                aria-hidden="true"
              />
            </div>
          ))}
        </div>
      </>
    )
  } else if (page === 'share') {
    body = (
      <div
        {...areaProps('share')}
        className={`flex flex-col gap-2 px-4 py-4 ${areaProps('share').className}`}
        data-testid="branding-preview-page-share"
      >
        {areaLabel('share')}
        <span className="text-vp-xs font-semibold uppercase tracking-wide text-vp-text-muted">
          {t('brandingPreviewShare')}
        </span>
        <span className="block w-full max-w-md overflow-hidden rounded-vp-md border border-vp-border-subtle bg-vp-surface">
          {showOgImage && (
            <img
              src={ogSrc ?? undefined}
              alt=""
              data-testid="branding-preview-og-image"
              className="aspect-[1200/630] w-full object-cover"
            />
          )}
          <span className="flex min-w-0 flex-col gap-0.5 px-3 py-2">
            <span className="truncate text-vp-sm font-semibold text-vp-title">{displayName}</span>
            <span className="truncate text-vp-xs text-vp-text-muted">Votepit</span>
          </span>
        </span>
        <p className="m-0 text-vp-xs text-vp-text-muted">{t('brandingPreviewShareHint')}</p>
      </div>
    )
  } else if (page === 'widget') {
    body = (
      <div
        {...areaProps('widget')}
        className={`flex flex-col gap-3 px-4 py-4 ${areaProps('widget').className}`}
        data-testid="branding-preview-page-widget"
      >
        {areaLabel('widget')}
        <span className="text-vp-xs font-semibold uppercase tracking-wide text-vp-text-muted">
          {t('brandingPreviewWidget')}
        </span>
        <span>
          <Button type="button" testId="branding-preview-widget-button" variant="primary">
            {t('brandingPreviewWidgetButton')}
          </Button>
        </span>
      </div>
    )
  } else if (page === 'mail') {
    body = (
      <div
        {...areaProps('mail')}
        className={`flex flex-col gap-2 px-4 py-4 ${areaProps('mail').className}`}
        data-testid="branding-preview-page-mail"
      >
        {areaLabel('mail')}
        <span className="text-vp-xs font-semibold uppercase tracking-wide text-vp-text-muted">
          {t('brandingPreviewMail')}
        </span>
        <span className="truncate text-vp-sm text-vp-title">
          {t('brandingPreviewMailSender', { name: displayName })}
        </span>
        <span
          aria-hidden="true"
          className="h-1 w-full rounded-vp-sm"
          style={{
            backgroundColor: barVars['--vpds-c-topbar-background'] ?? 'var(--color-vp-accent)',
          }}
        />
      </div>
    )
  } else {
    body = (
      <>
        <div data-testid="branding-preview-profile" className="relative">
          {coverArea}
          {logoRow}
          {profileTabs('ideas')}
          {showIntro && (
            <p
              {...areaProps('intro')}
              className={`m-0 px-4 pb-3 text-vp-sm text-vp-text-secondary ${areaProps('intro').className}`}
            >
              {areaLabel('intro')}
              {introText}
            </p>
          )}
        </div>
        <span
          {...areaProps('wording')}
          className={`block px-4 pt-3 text-vp-xs text-vp-text-muted ${areaProps('wording').className}`}
        >
          {areaLabel('wording')}
          {statLabel(PREVIEW_IDEA_COUNT)}
        </span>
        {state === 'empty' ? (
          <div
            {...areaProps('wording')}
            className={`flex flex-col gap-2 px-4 py-6 text-center ${areaProps('wording').className}`}
            data-testid="branding-preview-empty"
          >
            {areaLabel('wording')}
            <p className="m-0 text-vp-sm font-semibold text-vp-title">{tBoard('noIdeasTitle')}</p>
            <p className="m-0 text-vp-sm text-vp-text-secondary">
              {wordingText('empty_state', tBoard('noIdeasDescription'))}
            </p>
          </div>
        ) : (
          <>
            {voteRow(ideaTitle, 12, 2)}
            {voteRow(t('brandingPreviewIdeaTitleAlt'), 5, 1)}
          </>
        )}
      </>
    )
  }

  return (
    <div className="flex flex-col gap-2">
      {/* biome-ignore lint/a11y/useKeyWithClickEvents: pointer aid only; keyboard users pick areas from the panel's area list */}
      {/* biome-ignore lint/a11y/useKeyWithMouseEvents: pointer aid only; keyboard users pick areas from the panel's area list */}
      <div
        role="group"
        aria-label={t('brandingPreviewLabel')}
        data-testid="branding-preview-frame"
        data-device={device}
        data-page={page}
        data-state={state}
        {...presetAttributes}
        data-theme={previewTheme}
        style={rootVars}
        onMouseOver={compact ? undefined : onMouseOver}
        onMouseLeave={() => setHoverArea(null)}
        onClick={compact ? undefined : onClick}
        className={`w-full overflow-hidden rounded-vp-md border border-vp-border-subtle bg-vp-bg motion-reduce:transition-none`}
      >
        {topbar}
        {body}
        {footerEl}
        {badgeEl}
        {/* Options the plan does not include: kept, not applied, named by their tier. */}
        {!secondaryAllowed && (
          <p className="flex flex-wrap items-center gap-x-2 border-t border-vp-rule px-4 py-3 text-vp-xs text-vp-text-muted">
            <Badge tone="neutral" testId="branding-preview-locked-secondary">
              {tierLabel(secondaryRequiredPlan)}
            </Badge>
            <span>{t('planUpgradeHint')}</span>
            <PlanUpgradeLink
              label={t('planUpgradeLinkLabel')}
              requiredPlan={secondaryRequiredPlan}
            />
          </p>
        )}
        {!logoAllowed && logoUrl.trim() !== '' && (
          <p
            data-testid="branding-preview-locked-logo"
            className="m-0 px-4 pb-2 text-vp-xs text-vp-text-muted"
          >
            <Badge tone="neutral">{tierLabel(logoRequiredPlan)}</Badge>
          </p>
        )}
        {/* Pro values kept by the plan: only shown when they are set, so a Free board stays quiet. */}
        {!presetsOn &&
          (typography !== 'default' || shape !== 'rounded' || density !== 'comfortable') && (
            <LockedNote
              testId="branding-preview-locked-presets"
              tier={tierLabel(requiredOf('presets'))}
              hint={t('planUpgradeHint')}
              requiredPlan={requiredOf('presets')}
              linkLabel={t('planUpgradeLinkLabel')}
            />
          )}
        {!wordingOn &&
          wording !== null &&
          Object.values(wording).some((v) => (v ?? '').trim() !== '') && (
            <LockedNote
              testId="branding-preview-locked-wording"
              tier={tierLabel(requiredOf('wording'))}
              hint={t('planUpgradeHint')}
              requiredPlan={requiredOf('wording')}
              linkLabel={t('planUpgradeLinkLabel')}
            />
          )}
        {!patternOn && isCoverPattern(coverPattern) && (
          <LockedNote
            testId="branding-preview-locked-pattern"
            tier={tierLabel(requiredOf('pattern'))}
            hint={t('planUpgradeHint')}
            requiredPlan={requiredOf('pattern')}
            linkLabel={t('planUpgradeLinkLabel')}
          />
        )}
        {!coverOn && coverSrc !== null && (
          <LockedNote
            testId="branding-preview-locked-cover"
            tier={tierLabel(requiredOf('cover'))}
            hint={t('planUpgradeHint')}
            requiredPlan={requiredOf('cover')}
            linkLabel={t('planUpgradeLinkLabel')}
          />
        )}
        {!linksOn &&
          ((backLink !== null && (backLink.label.trim() !== '' || backLink.url.trim() !== '')) ||
            footerNote.trim() !== '') && (
            <LockedNote
              testId="branding-preview-locked-links"
              tier={tierLabel(requiredOf('links'))}
              hint={t('planUpgradeHint')}
              requiredPlan={requiredOf('links')}
              linkLabel={t('planUpgradeLinkLabel')}
            />
          )}
        {!roadmapOn && roadmapHidden && (
          <LockedNote
            testId="branding-preview-locked-roadmap"
            tier={tierLabel(requiredOf('roadmap'))}
            hint={t('planUpgradeHint')}
            requiredPlan={requiredOf('roadmap')}
            linkLabel={t('planUpgradeLinkLabel')}
          />
        )}
        {!imagesOn && (faviconSrc !== null || ogSrc !== null) && (
          <LockedNote
            testId="branding-preview-locked-images"
            tier={tierLabel(requiredOf('images'))}
            hint={t('planUpgradeHint')}
            requiredPlan={requiredOf('images')}
            linkLabel={t('planUpgradeLinkLabel')}
          />
        )}
      </div>
      {corrections.length > 0 && (
        <p data-testid="branding-preview-adjusted" className="m-0 text-vp-xs text-vp-text-muted">
          {t('brandingPreviewAdjusted')}
        </p>
      )}
    </div>
  )
}

/**
 * Browser-tab mock above the desktop frame: board name and the board's icon.
 * Clicking it reports the favicon area, like the frame does.
 */
export function BrowserTabMock({
  boardName,
  faviconSrc,
  selected,
  onAreaFocus,
}: {
  boardName: string
  faviconSrc: string | null
  selected: boolean
  onAreaFocus?: (area: BuilderArea) => void
}) {
  const t = useT('adminPage')
  const displayName = boardName.trim() !== '' ? boardName : t('brandingPreviewFallbackName')
  return (
    // biome-ignore lint/a11y/noStaticElementInteractions lint/a11y/useKeyWithClickEvents: pointer aid only; keyboard users pick the favicon area from the panel's area list
    <div
      data-testid="branding-preview-tab"
      data-builder-area="favicon"
      onClick={() => onAreaFocus?.('favicon')}
      className={`flex min-h-9 w-full min-w-0 items-center gap-2 py-1 rounded-t-vp-md border border-b-0 bg-vp-surface px-3 text-vp-xs text-vp-title ${selected ? 'border-vp-accent' : 'border-vp-border-subtle'}`}
      title={t('brandingPreviewTabLabel')}
    >
      <span className="flex size-4 shrink-0 items-center justify-center overflow-hidden">
        {faviconSrc !== null ? (
          <img
            src={faviconSrc}
            alt=""
            data-testid="branding-preview-tab-icon"
            className="size-4 object-contain"
          />
        ) : (
          <VotepitMark />
        )}
      </span>
      <span className="min-w-0 truncate" data-testid="branding-preview-tab-title">
        {`${displayName} · Votepit`}
      </span>
    </div>
  )
}

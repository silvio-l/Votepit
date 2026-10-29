/**
 * Full-screen branding editor (P-27): a sticky top bar (back, page switch,
 * device toggle, save state), an accordion settings panel on the left and a
 * device-framed live preview on the right. Below 1024 px the panel and the
 * canvas become two tabs. Used only on the dedicated design route; the board
 * settings page shows a compact card that links here.
 */

import { Badge, Button, ConfirmDialog, InlineNotice } from '@votepit/ui/ds'
import { ArrowLeft, Monitor, Smartphone, Tablet } from 'lucide-react'
import {
  type FocusEvent,
  type FormEvent,
  type ReactNode,
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
} from 'react'
import type { LogoPlateChoice } from '../lib/api/boardAdmin'
import type { BoardThemeMode } from '../lib/api/model'
import type { DensityPreset, ShapeStyle, TypographyPreset } from '../lib/branding'
import type { RequiredPlan } from '../lib/entitlements'
import { type Language, useT } from '../lib/i18n/context'
import type { EditorGroupId } from '../lib/onboarding'
import {
  BrandingPreview,
  type BrandingPreviewWording,
  BrowserTabMock,
  type BuilderArea,
  type BuilderDevice,
  type BuilderPage,
  type BuilderState,
  type PreviewGate,
  type PreviewGateKey,
} from './BrandingPreview'

const PAGES: BuilderPage[] = ['board', 'idea', 'submit', 'roadmap', 'share', 'widget', 'mail']
const DEVICES: BuilderDevice[] = ['desktop', 'tablet', 'phone']
const AREAS: BuilderArea[] = [
  'topbar',
  'cover',
  'logo',
  'intro',
  'votes',
  'wording',
  'badge',
  'footer',
  'roadmap',
  'favicon',
  'share',
  'widget',
  'mail',
]

/** Settings field each canvas area edits (ids of the fields in the panel). */
const AREA_FIELD: Record<BuilderArea, string> = {
  topbar: 'admin-secondary-color',
  cover: 'admin-board-asset-cover-input',
  logo: 'admin-logo-url',
  intro: 'admin-intro',
  votes: 'admin-primary-color',
  wording: 'branding-pro-wording-idea_plural',
  badge: 'admin-hide-badge',
  footer: 'admin-board-footer-note',
  roadmap: 'branding-pro-roadmap-hidden',
  favicon: 'admin-board-asset-favicon-input',
  share: 'admin-board-asset-og-input',
  widget: 'admin-primary-color',
  mail: 'admin-primary-color',
}

/** Page an area lives on; the area list switches to it. */
const AREA_PAGE: Partial<Record<BuilderArea, BuilderPage>> = {
  footer: 'board',
  roadmap: 'roadmap',
  share: 'share',
  widget: 'widget',
  mail: 'mail',
}

/**
 * Field focus → canvas: the page, page state and area the field edits. Focusing a
 * field in the panel switches the canvas to it (ids of the fields in the panel).
 */
interface FieldFocus {
  page?: BuilderPage
  state?: BuilderState
  area?: BuilderArea
}
const FIELD_FOCUS: Record<string, FieldFocus> = {
  'admin-primary-color': { area: 'topbar' },
  'admin-secondary-color': { area: 'topbar' },
  'admin-logo-input': { area: 'logo' },
  'admin-logo-url': { area: 'logo' },
  'admin-board-asset-cover-input': { area: 'cover' },
  'admin-cover-pattern': { area: 'cover' },
  'admin-intro': { page: 'board', area: 'intro' },
  'admin-hide-badge': { area: 'badge' },
  'admin-board-asset-favicon-input': { area: 'favicon' },
  'admin-board-asset-og-input': { page: 'share', area: 'share' },
  'admin-board-link-url': { page: 'board', area: 'footer' },
  'admin-board-link-label': { page: 'board', area: 'footer' },
  'admin-board-footer-note': { page: 'board', area: 'footer' },
  'branding-pro-typography': { page: 'board' },
  'branding-pro-shape': { page: 'board' },
  'branding-pro-density': { page: 'board' },
  'branding-pro-roadmap-hidden': { page: 'roadmap', area: 'roadmap' },
  'branding-pro-wording-idea_singular': { page: 'idea', area: 'wording' },
  'branding-pro-wording-idea_plural': { page: 'board', state: 'default', area: 'wording' },
  'branding-pro-wording-empty_state': { page: 'board', state: 'empty', area: 'wording' },
  'branding-pro-wording-submit_button': { page: 'submit', state: 'default', area: 'wording' },
  'branding-pro-wording-thank_you': { page: 'submit', state: 'thanks', area: 'wording' },
  'branding-pro-wording-vote_for': { page: 'board', area: 'votes' },
  'branding-pro-wording-vote_against': { page: 'board', area: 'votes' },
}

function fieldFocusFor(id: string): FieldFocus | null {
  if (id.startsWith('branding-pro-link-')) return { page: 'board', area: 'topbar' }
  return FIELD_FOCUS[id] ?? null
}

export type BrandingGroupId = EditorGroupId

/**
 * Accordion group of the panel. `badge` names the lowest plan that unlocks its
 * locked fields; `error` is the outcome of the group's last failed save, and
 * `dirty` marks unsaved changes in it.
 */
export interface BrandingEditorGroup {
  id: BrandingGroupId
  icon: ReactNode
  label: string
  badge: RequiredPlan | null
  dirty: boolean
  error: string | null
  content: ReactNode
}

const AREA_GROUP: Record<BuilderArea, BrandingGroupId> = {
  topbar: 'colors',
  cover: 'images',
  logo: 'images',
  intro: 'texts',
  votes: 'colors',
  wording: 'texts',
  badge: 'advanced',
  footer: 'advanced',
  roadmap: 'advanced',
  favicon: 'images',
  share: 'images',
  widget: 'colors',
  mail: 'colors',
}

/** Device content widths in CSS px; the frame adds its bezel around them. */
const DEVICE_PX: Record<BuilderDevice, number> = { desktop: 1024, tablet: 768, phone: 390 }
const TABLET_BEZEL = 24
const PHONE_BEZEL = 12

export interface BrandingBuilderValues {
  primary: string
  /** P-26 display mode being edited (the Darstellung select). */
  themeMode?: BoardThemeMode
  secondary: string
  /** Highlight colour and per-mode values, as unsaved in the form (v0.1.5). */
  accent?: string
  primaryDark?: string
  accentDark?: string
  /** Logo plate choice and whether the thin edge around the logo is hidden (v0.1.5). */
  logoPlate?: LogoPlateChoice
  logoRingHidden?: boolean
  logo: string
  /** Topbar wordmark (pending or stored), or null. */
  wordmark?: string | null
  /** Contrast metadata of the stored wordmark (see brandPlate.ts), or null. */
  wordmarkInk?: number | null
  /** Optional light wordmark (pending or stored), or null. */
  wordmarkLight?: string | null
  wordmarkLightInk?: number | null
  /** Mean ink of the logo (board_assets.ink_luminance): the auto plate checks it on the bar. */
  logoInk?: number | null
  intro: string
  hideBadge: boolean
  /** Cover image URL (pending or stored), or null for the default cover art. */
  coverSrc: string | null
  /** Header link labels shown in the top bar (empty when the plan does not include them). */
  headerLinkLabels: string[]
  /** Custom vote words (Pro wording); null when not customised. */
  voteWords: { up: string; down: string } | null
  /** Pro presets, cover pattern, wording and footer values, as unsaved in the form. */
  typography?: TypographyPreset
  shape?: ShapeStyle
  density?: DensityPreset
  coverPattern?: string | null
  wording?: BrandingPreviewWording | null
  backLink?: { label: string; url: string } | null
  footerNote?: string
  roadmapHidden?: boolean
  /** Browser-tab icon and share image (pending or stored). */
  faviconSrc?: string | null
  ogSrc?: string | null
}

export interface BrandingBuilderPlan {
  secondaryAllowed: boolean
  /** Lite+ gates (v0.1.5); a missing entry counts as not allowed in the preview. */
  accentAllowed?: boolean
  perModeAllowed?: boolean
  logoPlateAllowed?: boolean
  logoAllowed: boolean
  introAllowed: boolean
  badgeHideAllowed: boolean
  secondaryRequiredPlan: RequiredPlan | null
  logoRequiredPlan: RequiredPlan | null
  /** Plan gates of the Pro values; a missing entry counts as allowed. */
  gates?: Partial<Record<PreviewGateKey, Partial<PreviewGate>>>
}

export interface BrandingBuilderProps {
  boardName: string
  /** Public board URL, shown in the desktop frame's address bar like a browser does. */
  boardUrl?: string
  /** Wording locale being edited: the preview's texts use it (see BrandingPreview). */
  previewLanguage?: Language
  values: BrandingBuilderValues
  plan: BrandingBuilderPlan
  dirty: boolean
  saving: boolean
  frozen: boolean
  onSave: () => void
  onDiscard: () => void
  onBack: () => void
  onSubmit: (e: FormEvent) => void
  groups: BrandingEditorGroup[]
  /** Group opened first (deep link `?group=`); defaults to colours. */
  initialGroup?: BrandingGroupId
  /** Notices and dialogs rendered at the end of the panel. */
  children?: ReactNode
}

/** Device chrome around the preview. The preview is laid out at true size; the frame is zoomed to fit the canvas. */
function DeviceFrame({
  device,
  address,
  topSlot,
  children,
}: {
  device: BuilderDevice
  /** Address shown in the desktop browser bar (scheme dropped, as browsers display it). */
  address?: string
  /** Rendered above the frame, inside its zoom (the browser-tab mock). */
  topSlot?: ReactNode
  children: ReactNode
}) {
  const wrapRef = useRef<HTMLDivElement>(null)
  const [zoom, setZoom] = useState(1)

  useLayoutEffect(() => {
    const el = wrapRef.current
    if (el === null) return
    const refit = () => {
      const bezel =
        device === 'tablet' ? TABLET_BEZEL * 2 : device === 'phone' ? PHONE_BEZEL * 2 : 0
      setZoom(Math.min(1, el.clientWidth / (DEVICE_PX[device] + bezel)))
    }
    refit()
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(refit)
    observer.observe(el)
    return () => observer.disconnect()
  }, [device])

  const screen = (
    <div style={{ width: DEVICE_PX[device] }} className="overflow-hidden bg-vp-bg">
      {children}
    </div>
  )

  let frame: ReactNode
  if (device === 'desktop') {
    frame = (
      <div
        className="overflow-hidden rounded-xl border border-vp-border bg-vp-surface shadow-md"
        style={{ width: DEVICE_PX.desktop }}
      >
        <div
          className="flex h-9 items-center gap-2 border-b border-vp-rule bg-vp-surface-frost px-3"
          aria-hidden="true"
        >
          <span className="size-2.5 rounded-full bg-vp-border" />
          <span className="size-2.5 rounded-full bg-vp-border" />
          <span className="size-2.5 rounded-full bg-vp-border" />
          <span
            className="ml-3 min-h-5 w-64 max-w-[60%] truncate rounded-full bg-vp-bg px-3 text-vp-xs leading-5 text-vp-text-secondary"
            data-testid="branding-builder-address"
          >
            {address?.replace(/^https?:\/\//, '') ?? ''}
          </span>
        </div>
        {screen}
      </div>
    )
  } else if (device === 'tablet') {
    frame = (
      <div className="rounded-[2rem] bg-vp-ink shadow-md" style={{ padding: TABLET_BEZEL }}>
        <div className="overflow-hidden rounded-[1rem]">{screen}</div>
      </div>
    )
  } else {
    frame = (
      <div
        className="relative rounded-[2.75rem] bg-vp-ink shadow-md"
        style={{ padding: PHONE_BEZEL }}
      >
        <span
          aria-hidden="true"
          className="absolute left-1/2 top-3 h-1.5 w-20 -translate-x-1/2 rounded-full bg-vp-surface-frost/30"
        />
        <div className="overflow-hidden rounded-[2.1rem] pt-6">{screen}</div>
      </div>
    )
  }

  return (
    <div
      ref={wrapRef}
      className="flex w-full justify-center py-6"
      data-testid="branding-editor-device"
      data-device={device}
    >
      <div style={{ zoom }} className="shrink-0">
        {topSlot !== undefined && (
          <div style={{ width: DEVICE_PX[device] }} className="max-w-full">
            {topSlot}
          </div>
        )}
        {frame}
      </div>
    </div>
  )
}

export function BrandingBuilder({
  boardName,
  boardUrl,
  previewLanguage,
  values,
  plan,
  dirty,
  saving,
  frozen,
  onSave,
  onDiscard,
  onBack,
  onSubmit,
  groups,
  initialGroup,
  children,
}: BrandingBuilderProps) {
  const t = useT('adminPage')
  const tCommon = useT('common')
  const [tab, setTab] = useState<'settings' | 'preview'>('settings')
  const [device, setDevice] = useState<BuilderDevice>('desktop')
  const [page, setPage] = useState<BuilderPage>('board')
  const [pageState, setPageState] = useState<BuilderState>('default')
  const [applyLocked, setApplyLocked] = useState(false)
  const [selectedArea, setSelectedArea] = useState<BuilderArea | null>(null)
  const [openGroups, setOpenGroups] = useState<Set<BrandingGroupId>>(
    () => new Set<BrandingGroupId>([initialGroup ?? 'colors']),
  )
  const [pendingFocus, setPendingFocus] = useState<string | null>(null)
  const [leaveOpen, setLeaveOpen] = useState(false)
  const canvasRef = useRef<HTMLElement>(null)
  // Set when an area or field switches the canvas: the new area is scrolled into view once rendered.
  const scrollPending = useRef(false)
  // Our own focus() call must not re-point the canvas at the field's default area.
  const skipFocusSync = useRef(false)
  const hasLocked =
    !plan.secondaryAllowed ||
    !plan.logoAllowed ||
    !plan.introAllowed ||
    !plan.badgeHideAllowed ||
    Object.values(plan.gates ?? {}).some((gate) => gate?.allowed === false)

  const requestBack = () => {
    if (dirty) setLeaveOpen(true)
    else onBack()
  }

  // Escape leaves the editor; with unsaved changes it asks first. Skipped while another dialog is open.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape' || leaveOpen || document.querySelector('[role="dialog"]') !== null)
        return
      if (dirty) setLeaveOpen(true)
      else onBack()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [dirty, leaveOpen, onBack])

  // Focus runs after the owning accordion group has opened in the same commit.
  useEffect(() => {
    if (pendingFocus === null) return
    const el = document.getElementById(pendingFocus)
    if (el !== null) {
      el.scrollIntoView({ block: 'center' })
      skipFocusSync.current = true
      el.focus()
      skipFocusSync.current = false
    }
    setPendingFocus(null)
  }, [pendingFocus])

  // After an area or field switched the canvas, bring its element into view.
  // page and pageState are triggers only: the canvas re-renders on a page switch, so the scroll waits for it.
  // biome-ignore lint/correctness/useExhaustiveDependencies: see the comment above
  useEffect(() => {
    if (!scrollPending.current || selectedArea === null) return
    scrollPending.current = false
    const el = canvasRef.current?.querySelector<HTMLElement>(
      `[data-builder-area="${selectedArea}"]`,
    )
    if (el !== undefined && el !== null && typeof el.scrollIntoView === 'function')
      el.scrollIntoView({ block: 'center' })
  }, [selectedArea, page, pageState])

  const focusArea = (area: BuilderArea) => {
    setSelectedArea(area)
    const areaPage = AREA_PAGE[area]
    if (areaPage !== undefined) setPage(areaPage)
    setTab('settings')
    setOpenGroups((prev) => new Set(prev).add(AREA_GROUP[area]))
    scrollPending.current = true
    setPendingFocus(AREA_FIELD[area])
  }

  // Focusing a field in the panel points the canvas at what it edits (page, page state, area).
  const onPanelFocus = (e: FocusEvent<HTMLElement>) => {
    if (skipFocusSync.current) return
    const id = (e.target as HTMLElement).closest<HTMLElement>('[id]')?.id ?? ''
    const target = fieldFocusFor(id)
    if (target === null) return
    setPage(target.page ?? page)
    setPageState(target.state ?? 'default')
    setSelectedArea(target.area ?? null)
    scrollPending.current = target.area !== undefined
  }

  const pageButton = (p: BuilderPage, extraTestId: string) => (
    <button
      key={p}
      type="button"
      data-testid={`${extraTestId}-${p}`}
      aria-pressed={page === p}
      onClick={() => {
        setPage(p)
        setPageState('default')
      }}
      className={`min-h-9 rounded-vp-sm px-3 text-vp-xs font-medium ${page === p ? 'bg-vp-ink text-vp-on-ink' : 'text-vp-text-secondary hover:text-vp-title'}`}
    >
      {t(`builderPage_${p}`)}
    </button>
  )

  const deviceIcon = { desktop: Monitor, tablet: Tablet, phone: Smartphone } as const

  return (
    <form
      id="admin-branding-form"
      onSubmit={onSubmit}
      noValidate
      data-testid="branding-editor"
      className="flex min-h-dvh flex-col bg-vp-bg text-vp-title"
    >
      <header
        data-testid="branding-editor-topbar"
        className="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b border-vp-border-subtle bg-vp-surface px-3 sm:px-4"
      >
        <button
          type="button"
          data-testid="branding-editor-back"
          onClick={requestBack}
          className="flex min-h-11 shrink-0 items-center gap-1.5 rounded-vp-sm px-2 text-vp-sm font-medium text-vp-text-secondary hover:text-vp-title"
        >
          <ArrowLeft size={18} aria-hidden="true" />
          <span className="hidden sm:inline">{t('builderBack')}</span>
        </button>
        <span
          className="min-w-0 truncate text-vp-sm font-semibold text-vp-title"
          data-testid="branding-editor-board"
          title={boardName}
        >
          {boardName}
        </span>

        <div className="mx-auto hidden items-center gap-3 lg:flex">
          <div
            role="group"
            aria-label={t('builderPageLabel')}
            className="flex gap-1 rounded-vp-md bg-vp-surface-frost p-1"
          >
            {PAGES.map((p) => pageButton(p, 'branding-editor-page'))}
          </div>
          <div role="group" aria-label={t('builderDeviceLabel')} className="flex gap-1">
            {DEVICES.map((d) => {
              const Icon = deviceIcon[d]
              return (
                <button
                  key={d}
                  type="button"
                  data-testid={`branding-editor-device-${d}`}
                  aria-label={t(`builderDevice_${d}`)}
                  title={t(`builderDevice_${d}`)}
                  aria-pressed={device === d}
                  onClick={() => setDevice(d)}
                  className={`flex size-9 items-center justify-center rounded-vp-sm ${device === d ? 'bg-vp-ink text-vp-on-ink' : 'text-vp-text-secondary hover:text-vp-title'}`}
                >
                  <Icon size={18} aria-hidden="true" />
                </button>
              )
            })}
          </div>
        </div>

        {/* Below lg: page and device move into one menu. */}
        <details className="relative ml-auto lg:hidden" data-testid="branding-editor-view-menu">
          <summary className="flex min-h-11 cursor-pointer list-none items-center rounded-vp-sm border border-vp-border-subtle px-3 text-vp-xs font-medium text-vp-text-secondary">
            {t('builderView')}
          </summary>
          <div className="absolute right-0 z-40 mt-2 flex w-56 flex-col gap-2 rounded-vp-md border border-vp-border-subtle bg-vp-surface p-2 shadow-md">
            <div role="group" aria-label={t('builderPageLabel')} className="flex flex-col gap-1">
              {PAGES.map((p) => pageButton(p, 'branding-editor-menu-page'))}
            </div>
            <Button
              type="button"
              variant="secondary"
              testId="branding-editor-menu-discard"
              disabled={!dirty || saving || frozen}
              onClick={onDiscard}
            >
              {t('builderDiscard')}
            </Button>
            <div role="group" aria-label={t('builderDeviceLabel')} className="flex gap-1">
              {DEVICES.map((d) => (
                <button
                  key={d}
                  type="button"
                  data-testid={`branding-editor-menu-device-${d}`}
                  aria-pressed={device === d}
                  onClick={() => setDevice(d)}
                  className={`min-h-9 flex-1 rounded-vp-sm text-vp-xs font-medium ${device === d ? 'bg-vp-ink text-vp-on-ink' : 'text-vp-text-secondary'}`}
                >
                  {t(`builderDevice_${d}`)}
                </button>
              ))}
            </div>
          </div>
        </details>

        <div className="hidden items-center gap-3 xl:flex">
          <span
            role="status"
            data-testid="branding-editor-status"
            className={`text-vp-xs ${dirty ? 'font-medium text-vp-warn' : 'text-vp-text-muted'}`}
          >
            {dirty ? t('builderUnsaved') : t('builderSaved')}
          </span>
        </div>
        <span className="hidden shrink-0 sm:block">
          <Button
            type="button"
            variant="secondary"
            testId="branding-editor-discard"
            disabled={!dirty || saving || frozen}
            onClick={onDiscard}
          >
            {t('builderDiscard')}
          </Button>
        </span>
        <span className="shrink-0">
          <Button
            type="button"
            variant="primary"
            testId="branding-editor-save"
            disabled={saving || frozen}
            loading={saving}
            onClick={onSave}
          >
            {saving ? t('savingEllipsis') : t('builderSave')}
          </Button>
        </span>
      </header>

      {/* Below lg: the panel and the canvas are tabs. */}
      <div
        role="group"
        aria-label={t('builderTabsLabel')}
        className="flex gap-2 border-b border-vp-border-subtle bg-vp-surface p-2 lg:hidden"
      >
        <button
          type="button"
          data-testid="branding-builder-tab-settings"
          aria-pressed={tab === 'settings'}
          onClick={() => setTab('settings')}
          className={`min-h-11 flex-1 rounded-vp-sm px-3 text-vp-sm font-medium ${tab === 'settings' ? 'bg-vp-ink text-vp-on-ink' : 'border border-vp-border-subtle text-vp-text-secondary'}`}
        >
          {t('builderTabSettings')}
        </button>
        <button
          type="button"
          data-testid="branding-builder-tab-preview"
          aria-pressed={tab === 'preview'}
          onClick={() => setTab('preview')}
          className={`min-h-11 flex-1 rounded-vp-sm px-3 text-vp-sm font-medium ${tab === 'preview' ? 'bg-vp-ink text-vp-on-ink' : 'border border-vp-border-subtle text-vp-text-secondary'}`}
        >
          {t('builderTabPreview')}
        </button>
      </div>

      <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
        <aside
          data-testid="branding-editor-panel"
          onFocus={onPanelFocus}
          className={`w-full shrink-0 flex-col border-vp-border-subtle bg-vp-surface lg:h-[calc(100dvh-3.5rem)] lg:w-[clamp(18rem,28vw,22.5rem)] lg:overflow-y-auto lg:border-r ${tab === 'preview' ? 'hidden lg:flex' : 'flex'}`}
        >
          <nav
            aria-label={t('builderAreasLabel')}
            data-testid="branding-builder-areas"
            className="border-b border-vp-rule px-4 py-4"
          >
            <p className="m-0 mb-2 text-vp-xs font-semibold uppercase tracking-wide text-vp-text-muted">
              {t('builderAreasLabel')}
            </p>
            <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
              {AREAS.map((area) => (
                <li key={area}>
                  <button
                    type="button"
                    data-testid={`branding-builder-area-${area}`}
                    aria-pressed={selectedArea === area}
                    onClick={() => focusArea(area)}
                    className={`min-h-9 rounded-vp-sm border px-2.5 text-vp-xs font-medium ${selectedArea === area ? 'border-vp-ink text-vp-title' : 'border-vp-border-subtle text-vp-text-secondary hover:text-vp-title'}`}
                  >
                    {t(`builderArea_${area}`)}
                  </button>
                </li>
              ))}
            </ul>
          </nav>

          <fieldset disabled={frozen} className="m-0 min-w-0 border-0 p-0">
            {groups.map((g) => (
              <details
                key={g.id}
                data-testid={`branding-editor-group-${g.id}`}
                open={openGroups.has(g.id)}
                onToggle={(e) => {
                  const open = e.currentTarget.open
                  setOpenGroups((prev) => {
                    const next = new Set(prev)
                    if (open) next.add(g.id)
                    else next.delete(g.id)
                    return next
                  })
                }}
                className="group border-b border-vp-rule"
              >
                <summary className="flex min-h-12 cursor-pointer list-none items-center gap-2.5 px-4 py-3 text-vp-sm font-semibold text-vp-title">
                  <span className="text-vp-text-secondary">{g.icon}</span>
                  <span className="min-w-0 flex-1">{g.label}</span>
                  {g.dirty && (
                    <span
                      role="img"
                      aria-label={t('builderUnsaved')}
                      data-testid={`branding-editor-group-${g.id}-dirty`}
                      className="size-2 shrink-0 rounded-full bg-vp-warn"
                    />
                  )}
                  {g.badge !== null && (
                    <Badge tone="neutral" testId={`branding-editor-group-${g.id}-plan`}>
                      {g.badge === 'pro'
                        ? tCommon('planAvailableFromPro')
                        : g.badge === 'starter'
                          ? tCommon('planAvailableFromStarter')
                          : tCommon('planAvailableFromLite')}
                    </Badge>
                  )}
                </summary>
                <div className="flex flex-col gap-5 px-4 pb-5">
                  {g.error !== null && (
                    <InlineNotice tone="error" testId={`branding-editor-group-${g.id}-error`}>
                      {g.error}
                    </InlineNotice>
                  )}
                  {g.content}
                </div>
              </details>
            ))}
          </fieldset>

          <div className="flex flex-col gap-4 px-4 py-5">{children}</div>
        </aside>

        <section
          ref={canvasRef}
          data-testid="branding-builder-canvas"
          aria-label={t('builderCanvasLabel')}
          className={`min-w-0 flex-1 bg-vp-surface-frost p-4 sm:p-6 lg:h-[calc(100dvh-3.5rem)] lg:overflow-y-auto ${tab === 'settings' ? 'hidden lg:block' : 'block'}`}
        >
          <div className="mb-4 flex flex-wrap items-center justify-end gap-2">
            {(page === 'board' || page === 'submit') && (
              <div
                role="group"
                aria-label={t('builderStateLabel')}
                data-testid="branding-editor-state"
                className="flex gap-1 rounded-vp-md bg-vp-surface p-1"
              >
                {(page === 'board'
                  ? (['default', 'empty'] as const)
                  : (['default', 'thanks'] as const)
                ).map((s) => (
                  <button
                    key={s}
                    type="button"
                    data-testid={`branding-editor-state-${s}`}
                    aria-pressed={pageState === s}
                    onClick={() => setPageState(s)}
                    className={`min-h-9 rounded-vp-sm px-3 text-vp-xs font-medium ${pageState === s ? 'bg-vp-ink text-vp-on-ink' : 'text-vp-text-secondary hover:text-vp-title'}`}
                  >
                    {t(`builderState_${s}`)}
                  </button>
                ))}
              </div>
            )}
            {hasLocked && (
              <button
                type="button"
                data-testid="branding-builder-preview-locked"
                aria-pressed={applyLocked}
                onClick={() => setApplyLocked((v) => !v)}
                className={`min-h-9 rounded-vp-sm px-3 text-vp-xs font-medium ${applyLocked ? 'bg-vp-ink text-vp-on-ink' : 'border border-dashed border-vp-border-subtle text-vp-text-secondary'}`}
              >
                {t('builderPreviewLocked')}
              </button>
            )}
          </div>
          <DeviceFrame
            device={device}
            address={boardUrl}
            topSlot={
              device === 'desktop' ? (
                <BrowserTabMock
                  boardName={boardName}
                  faviconSrc={values.faviconSrc ?? null}
                  selected={selectedArea === 'favicon'}
                  onAreaFocus={focusArea}
                />
              ) : undefined
            }
          >
            <BrandingPreview
              boardName={boardName}
              previewLanguage={previewLanguage}
              primaryColor={values.primary}
              secondaryColor={values.secondary}
              accentColor={values.accent ?? ''}
              primaryColorDark={values.primaryDark ?? ''}
              accentColorDark={values.accentDark ?? ''}
              accentAllowed={plan.accentAllowed ?? false}
              perModeAllowed={plan.perModeAllowed ?? false}
              logoPlate={values.logoPlate ?? null}
              logoRingHidden={values.logoRingHidden ?? false}
              logoPlateAllowed={plan.logoPlateAllowed ?? false}
              logoUrl={values.logo}
              wordmarkSrc={values.wordmark ?? null}
              wordmarkInk={values.wordmarkInk ?? null}
              wordmarkLightSrc={values.wordmarkLight ?? null}
              wordmarkLightInk={values.wordmarkLightInk ?? null}
              logoInk={values.logoInk ?? null}
              secondaryAllowed={plan.secondaryAllowed}
              secondaryRequiredPlan={plan.secondaryRequiredPlan}
              logoAllowed={plan.logoAllowed}
              logoRequiredPlan={plan.logoRequiredPlan}
              introText={values.intro}
              introAllowed={plan.introAllowed}
              hideBadge={values.hideBadge}
              badgeHideAllowed={plan.badgeHideAllowed}
              page={page}
              device={device}
              applyLocked={applyLocked}
              selectedArea={selectedArea}
              onAreaFocus={focusArea}
              coverSrc={values.coverSrc}
              headerLinkLabels={values.headerLinkLabels}
              voteWords={values.voteWords}
              typography={values.typography}
              shape={values.shape}
              density={values.density}
              coverPattern={values.coverPattern}
              wording={values.wording}
              backLink={values.backLink}
              footerNote={values.footerNote}
              roadmapHidden={values.roadmapHidden}
              faviconSrc={values.faviconSrc}
              ogSrc={values.ogSrc}
              gates={plan.gates}
              state={pageState}
            />
          </DeviceFrame>
        </section>
      </div>

      <ConfirmDialog
        testId="branding-editor-leave"
        open={leaveOpen}
        title={t('builderLeaveTitle')}
        description={t('builderLeaveBody')}
        confirmLabel={t('builderLeaveAction')}
        cancelLabel={tCommon('action.cancel')}
        tone="danger"
        onConfirm={() => {
          setLeaveOpen(false)
          onDiscard()
          onBack()
        }}
        onCancel={() => setLeaveOpen(false)}
      />
    </form>
  )
}

/**
 * RoadmapPage — board-scoped, read-only Roadmap.
 *
 * Route: /:boardSlug/roadmap
 * Trust level: anon (publicly readable, no voting).
 *
 * Views: list (default) | columns | gantt (plan-gated, see roadmap_gantt_allowed).
 */

import type { Status } from '@votepit/ui'
import { statusDotClass } from '@votepit/ui'
import {
  Avatar,
  Badge,
  Button,
  cx,
  EmptyState,
  ErrorState,
  IconButton,
  IdeaCard,
  InlineNotice,
  LoadingState,
  PageShell,
  Section,
  Skeleton,
  Tabs,
  TextField,
} from '@votepit/ui/ds'

import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { BoardBrandFooter } from '../components/BoardBrandFooter'
import { BoardCoverBand } from '../components/BoardCoverBand'
import { BoardProfileTabs } from '../components/BoardProfileTabs'
import { BoardThemeMode } from '../components/BoardThemeMode'
import {
  boardRoadmapVisible,
  LocalizedHeader,
  ScopeLabel,
  useIsPhone,
} from '../components/LocalizedHeader'
import { MarkdownLite } from '../components/MarkdownLite'
import { NotFoundState } from '../components/NotFoundState'
import { useDsLabels } from '../hooks/useDsLabels'
import { useIdeaCardLabels } from '../hooks/useIdeaCardLabels'
import { useIdeaTranslation } from '../hooks/useIdeaTranslation'
import { useInView } from '../hooks/useInView'
import { useVoterPreview } from '../hooks/useVoterPreview'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import type { ApiError, IdeaStatus, RoadmapIdea, RoadmapResponse, User } from '../lib/api'
import { accountRoleFor, bootstrap, getRoadmap, logout, setIdeaDates } from '../lib/api'
import {
  boardInitialsName,
  boardLogoSrc,
  boardWordmarkPlated,
  boardWordmarkSrc,
} from '../lib/boardLogo'
import { statusLabel, toComponentStatus } from '../lib/boardStatuses'
import {
  type BrandingPro,
  brandingProAttributes,
  brandingStyle,
  coverAccentFor,
} from '../lib/branding'
import { legalLinksFor } from '../lib/features'
import { formatDate, intlLocale } from '../lib/formatDate'
import type { Language } from '../lib/i18n/context'
import { useI18n, useT } from '../lib/i18n/context'
import { useResolvedTheme } from '../lib/theme'
import { useIsOnline } from '../lib/useIsOnline'

// ── Helpers ──────────────────────────────────────────────────────────────────

type ViewMode = 'list' | 'columns' | 'gantt'

/** Per-status empty copy — a roadmap column says what is missing, not just "nothing". */
const EMPTY_KEYS: Record<
  'planned' | 'in_progress' | 'done',
  { title: string; description: string }
> = {
  planned: { title: 'emptyPlannedTitle', description: 'emptyPlannedDescription' },
  in_progress: { title: 'emptyInProgressTitle', description: 'emptyInProgressDescription' },
  done: { title: 'emptyDoneTitle', description: 'emptyDoneDescription' },
}

const SECTIONS: Array<{ key: 'planned' | 'in_progress' | 'done'; status: Status }> = [
  { key: 'planned', status: 'planned' },
  { key: 'in_progress', status: 'in-progress' },
  { key: 'done', status: 'done' },
]

// ── Section head ───────────────────────────────────────────────────────────────

/** Sheet-head title: the status word at head weight with its 8px colour dot. */
function SectionTitle({ status, label }: { status: Status; label: string }) {
  return (
    <span className="inline-flex items-center gap-2">
      <span
        aria-hidden="true"
        className={`size-2 rounded-vp-full shrink-0 ${statusDotClass[status]}`}
      />
      {label}
    </span>
  )
}

function SectionCount({ count }: { count: number }) {
  const t = useT('roadmapPage')
  return (
    <Badge tone="neutral">
      <span className="font-mono-num">{count}</span>{' '}
      {count !== 1 ? t('ideaPlural') : t('ideaSingular')}
    </Badge>
  )
}

function SectionEmpty({ sectionKey }: { sectionKey: 'planned' | 'in_progress' | 'done' }) {
  const t = useT('roadmapPage')
  const keys = EMPTY_KEYS[sectionKey]
  return (
    <EmptyState
      kind="no-ideas"
      testId={`roadmap-empty-${sectionKey}`}
      title={t(keys.title)}
      description={t(keys.description)}
    />
  )
}

// ── Ideas list (list view) ────────────────────────────────────────────────────

interface RoadmapIdeaRowProps {
  idea: RoadmapIdea
  boardSlug: string
  translationEnabled: boolean
  language: Language
  /** Status word of an idea, resolved against the board's registry (issue 03). */
  statusLabelFor: (idea: RoadmapIdea) => string
  /** Owner wording for the vote words (Pro). */
  brandingPro?: BrandingPro | null
}

function RoadmapIdeaRow({
  idea,
  boardSlug,
  translationEnabled,
  language,
  statusLabelFor,
  brandingPro,
}: RoadmapIdeaRowProps) {
  const t = useT('roadmapPage')

  // Same viewport-lazy translate strategy as the board list (see
  // useIdeaTranslation's caller in BoardPage.tsx) — the roadmap can list as
  // many ideas as the board itself, so cards outside the viewport must not
  // burn the translation:idea rate-limit bucket.
  const [inViewRef, inView] = useInView()
  const translation = useIdeaTranslation(
    boardSlug,
    idea.id,
    idea.title,
    idea.body,
    language,
    translationEnabled && inView,
  )

  const cardLabels = useIdeaCardLabels(null, brandingPro)
  const dsLabels = useDsLabels()
  return (
    <div ref={inViewRef}>
      <IdeaCard
        testId={`roadmap-idea-${idea.id}`}
        title={translation.displayTitle}
        problem={
          <>
            <MarkdownLite text={translation.displayBody} maxChars={120} />
            {translation.isTranslated && (
              <p className="mt-1 flex items-center gap-1 text-vp-xs text-vp-text-muted">
                <span>{t('machineTranslatedLabel')}</span>
                <span aria-hidden="true">·</span>
                <button
                  type="button"
                  className="underline hover:no-underline"
                  onClick={(e) => {
                    e.preventDefault()
                    translation.setShowOriginal(!translation.showOriginal)
                  }}
                >
                  {translation.showOriginal ? t('showTranslationLink') : t('showOriginalLink')}
                </button>
              </p>
            )}
          </>
        }
        status={toComponentStatus(idea.status)}
        statusLabel={statusLabelFor(idea)}
        up={idea.up_count}
        down={idea.down_count}
        commentCount={idea.comment_count}
        commentLabel={cardLabels.commentLabel}
        href={accountPath(`/${boardSlug}/idea/${idea.id}`)}
        readonlyVotes
        voteLabels={cardLabels.voteLabels}
        summaryLabels={cardLabels.summaryLabels}
        locale={cardLabels.locale}
        unreadLabel={dsLabels.unread}
        pendingLabel={dsLabels.pending}
      />
    </div>
  )
}

function IdeaList({
  ideas,
  boardSlug,
  translationEnabled,
  language,
  statusLabelFor,
  brandingPro,
}: {
  ideas: RoadmapIdea[]
  boardSlug: string
  translationEnabled: boolean
  language: Language
  statusLabelFor: (idea: RoadmapIdea) => string
  brandingPro?: BrandingPro | null
}) {
  const t = useT('roadmapPage')
  return (
    <ul
      className="ds-section-list ds-section-list--bare animate-vp-fade-in"
      aria-label={t('ideasAriaLabel')}
    >
      {ideas.map((idea) => (
        <li key={idea.id}>
          <RoadmapIdeaRow
            idea={idea}
            boardSlug={boardSlug}
            translationEnabled={translationEnabled}
            language={language}
            statusLabelFor={statusLabelFor}
            brandingPro={brandingPro}
          />
        </li>
      ))}
    </ul>
  )
}

// ── Idea card (columns view) ──────────────────────────────────────────────────

interface RoadmapIdeaCardProps {
  idea: RoadmapIdea
  boardSlug: string
  translationEnabled: boolean
  language: Language
  statusLabel: string
  brandingPro?: BrandingPro | null
}

function RoadmapIdeaCard({
  idea,
  boardSlug,
  translationEnabled,
  language,
  statusLabel,
  brandingPro,
}: RoadmapIdeaCardProps) {
  // The legacy roadmap card had no excerpt slot (title only), so there's no room for a
  // "machine-translated / show original" affordance here — only the title
  // itself needs to reflect the translated text.
  const [inViewRef, inView] = useInView()
  const translation = useIdeaTranslation(
    boardSlug,
    idea.id,
    idea.title,
    idea.body,
    language,
    translationEnabled && inView,
  )

  const cardLabels = useIdeaCardLabels(null, brandingPro)
  const dsLabels = useDsLabels()
  return (
    <div ref={inViewRef}>
      <IdeaCard
        variant="compact"
        testId={`roadmap-idea-${idea.id}`}
        title={translation.displayTitle}
        status={toComponentStatus(idea.status)}
        statusLabel={statusLabel}
        up={idea.up_count}
        down={idea.down_count}
        commentCount={idea.comment_count}
        commentLabel={cardLabels.commentLabel}
        href={accountPath(`/${boardSlug}/idea/${idea.id}`)}
        readonlyVotes
        voteLabels={cardLabels.voteLabels}
        summaryLabels={cardLabels.summaryLabels}
        locale={cardLabels.locale}
        unreadLabel={dsLabels.unread}
        pendingLabel={dsLabels.pending}
      />
    </div>
  )
}

// ── Gantt view ─────────────────────────────────────────────────────────────────

/** First 10 chars of a MySQL "YYYY-MM-DD[ HH:MM:SS]" or ISO string. */
function dateOnly(iso: string): string {
  return iso.slice(0, 10)
}

function toEpochDay(dateStr: string): number {
  return new Date(`${dateStr}T00:00:00Z`).getTime()
}

const DAY_MS = 24 * 60 * 60 * 1000

/** Position on the timeline as a 0–100 percentage, clamped to the visible band. */
function pct(dateStr: string, min: number, max: number): number {
  if (max <= min) return 0
  return Math.min(100, Math.max(0, ((toEpochDay(dateStr) - min) / (max - min)) * 100))
}

interface MonthTick {
  key: string
  label: string
  leftPct: number
  widthPct: number
  quarter: number
  year: number
}

/** Every calendar month that overlaps [min, max), each clipped to the visible
 * band and positioned as a left/width percentage — the month axis row. */
function monthTicks(min: number, max: number, language: Language): MonthTick[] {
  if (max <= min) return []
  const formatter = new Intl.DateTimeFormat(intlLocale(language), { month: 'short' })
  const ticks: MonthTick[] = []
  let cursor = new Date(min)
  cursor = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth(), 1))

  while (cursor.getTime() < max) {
    const monthStart = cursor.getTime()
    const nextMonth = Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth() + 1, 1)
    const sliceStart = Math.max(monthStart, min)
    const sliceEnd = Math.min(nextMonth, max)
    if (sliceEnd > sliceStart) {
      ticks.push({
        key: `${cursor.getUTCFullYear()}-${cursor.getUTCMonth()}`,
        label: formatter.format(cursor),
        leftPct: ((sliceStart - min) / (max - min)) * 100,
        widthPct: ((sliceEnd - sliceStart) / (max - min)) * 100,
        quarter: Math.floor(cursor.getUTCMonth() / 3) + 1,
        year: cursor.getUTCFullYear(),
      })
    }
    cursor = new Date(nextMonth)
  }

  return ticks
}

interface QuarterTick {
  key: string
  label: string
  leftPct: number
  widthPct: number
}

/** Groups consecutive month ticks that share a quarter/year into one cell,
 * spanning their combined width — the year/quarter row above the months. */
function quarterTicks(months: MonthTick[]): QuarterTick[] {
  const groups: QuarterTick[] = []
  for (const m of months) {
    const key = `${m.year}-Q${m.quarter}`
    const last = groups[groups.length - 1]
    if (last && last.key === key) {
      last.widthPct += m.widthPct
    } else {
      groups.push({
        key,
        label: `Q${m.quarter} ${m.year}`,
        leftPct: m.leftPct,
        widthPct: m.widthPct,
      })
    }
  }
  return groups
}

interface GanttBar {
  idea: RoadmapIdea
  status: Status
  /** Effective start: idea.start_date, or the idea's created_at as a fallback. */
  startDate: string
  /** Effective end, or null when the target is open/unknown ("not possible to say yet"). */
  endDate: string | null
}

interface GanttRowProps {
  bar: GanttBar
  boardSlug: string
  isAdmin: boolean
  language: Language
  translationEnabled: boolean
  timelineMin: number
  timelineMax: number
  monthTicks: MonthTick[]
  onDatesChanged: (ideaId: number, startDate: string | null, targetDate: string | null) => void
}

/** Title column width — shared by every row AND the axis header, so the
 * header's min/max dates line up with the actual track, not the label. */
const GANTT_LABEL_COL = 'sm:w-48 md:w-56'

function GanttRow({
  bar,
  boardSlug,
  isAdmin,
  language,
  translationEnabled,
  timelineMin,
  timelineMax,
  monthTicks: months,
  onDatesChanged,
}: GanttRowProps) {
  const t = useT('roadmapPage')
  const tCommon = useT('common')
  const [inViewRef, inView] = useInView()
  const translation = useIdeaTranslation(
    boardSlug,
    bar.idea.id,
    bar.idea.title,
    bar.idea.body,
    language,
    translationEnabled && inView,
  )
  const [editing, setEditing] = useState(false)
  const [startInput, setStartInput] = useState(bar.idea.start_date ?? '')
  const [targetInput, setTargetInput] = useState(bar.idea.target_date ?? '')
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const left = pct(bar.startDate, timelineMin, timelineMax)
  const right = bar.endDate ? pct(bar.endDate, timelineMin, timelineMax) : 100
  const width = Math.max(right - left, 1.5)
  const dateRangeLabel = bar.endDate
    ? `${formatDate(bar.startDate, language)} – ${formatDate(bar.endDate, language)}`
    : `${formatDate(bar.startDate, language)} – ${t('ganttOpenEndLabel')}`

  const openEdit = () => {
    setStartInput(bar.idea.start_date ?? '')
    setTargetInput(bar.idea.target_date ?? '')
    setError(null)
    setEditing(true)
  }

  const handleSave = async () => {
    if (pending) return
    if (startInput && targetInput && startInput > targetInput) {
      setError(t('ganttInvalidDateRangeError'))
      return
    }
    setError(null)
    setPending(true)
    const prevStart = bar.idea.start_date
    const prevTarget = bar.idea.target_date
    const nextStart = startInput || null
    const nextTarget = targetInput || null
    onDatesChanged(bar.idea.id, nextStart, nextTarget) // optimistic
    try {
      await setIdeaDates(boardSlug, bar.idea.id, nextStart, nextTarget)
      setEditing(false)
    } catch {
      onDatesChanged(bar.idea.id, prevStart, prevTarget) // rollback
      setError(t('ganttDatesUpdateError'))
    } finally {
      setPending(false)
    }
  }

  return (
    <div
      ref={inViewRef}
      className="group px-4 py-3.5 transition-colors duration-ds-fast hover:bg-vp-surface-frost sm:px-5"
    >
      <div className="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:gap-4">
        <div className={cx('min-w-0 w-full shrink-0', GANTT_LABEL_COL)}>
          <div className="flex items-center gap-2 min-w-0">
            <span
              aria-hidden="true"
              className={cx('size-2 rounded-vp-full shrink-0', statusDotClass[bar.status])}
            />
            <a
              href={accountPath(`/${boardSlug}/idea/${bar.idea.id}`)}
              title={translation.displayTitle}
              className="truncate text-vp-sm font-semibold text-vp-title underline-offset-2 decoration-vp-underline hover:underline"
            >
              {translation.displayTitle}
            </a>
          </div>
          <p className="mt-0.5 pl-4 truncate text-vp-xs text-vp-text-muted">{dateRangeLabel}</p>
        </div>
        <div className="flex flex-1 items-center gap-2 min-w-0">
          <div className="relative h-4 flex-1 min-w-vp-bar-min overflow-hidden rounded-vp-sm bg-vp-surface-frost ring-1 ring-inset ring-vp-rule transition-shadow duration-ds-fast group-hover:ring-vp-border-strong">
            {months.slice(1).map((m) => (
              <div
                key={m.key}
                aria-hidden="true"
                className="absolute inset-y-0 w-px bg-vp-rule"
                style={{ left: `${m.leftPct}%` }}
              />
            ))}
            <div
              className="absolute inset-y-0.5 overflow-hidden rounded-vp-sm transition-[left,width] duration-ds-emphasis ease-vp-out"
              style={{ left: `${left}%`, width: `${width}%` }}
              title={dateRangeLabel}
            >
              <div className={cx('h-full', statusDotClass[bar.status])} />
              {bar.endDate === null && (
                <div
                  aria-hidden="true"
                  className="absolute inset-y-0 right-0 w-2/5 bg-gradient-to-r from-transparent to-vp-surface-frost"
                />
              )}
            </div>
          </div>
          {isAdmin && (
            <IconButton
              icon={editing ? 'close' : 'pencil'}
              accessibleLabel={editing ? tCommon('action.close') : tCommon('action.edit')}
              testId="roadmap-edit-toggle"
              onClick={editing ? () => setEditing(false) : openEdit}
            />
          )}
        </div>
      </div>
      {isAdmin && editing && (
        <div className="mt-3 flex flex-wrap items-end gap-3 rounded-vp-sm border border-vp-rule bg-vp-surface-frost p-3.5 animate-vp-scale-in">
          <TextField
            testId="roadmap-gantt-start-date"
            type="date"
            label={t('ganttStartDateLabel')}
            value={startInput}
            onChange={(e) => setStartInput(e.target.value)}
            className="w-40"
          />
          <TextField
            testId="roadmap-gantt-target-date"
            type="date"
            label={t('ganttTargetDateLabel')}
            value={targetInput}
            onChange={(e) => setTargetInput(e.target.value)}
            className="w-40"
          />
          <Button
            type="button"
            variant="primary"
            testId="roadmap-save-dates"
            onClick={() => void handleSave()}
            disabled={pending}
          >
            {tCommon('action.save')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            testId="roadmap-cancel-dates"
            onClick={() => setEditing(false)}
            disabled={pending}
          >
            {tCommon('action.cancel')}
          </Button>
          {error && (
            <p role="alert" className="w-full text-vp-xs text-vp-vote-down-strong">
              {error}
            </p>
          )}
        </div>
      )}
    </div>
  )
}

interface GanttChartProps {
  bars: GanttBar[]
  boardSlug: string
  isAdmin: boolean
  language: Language
  translationEnabled: boolean
  onDatesChanged: (ideaId: number, startDate: string | null, targetDate: string | null) => void
}

function GanttChart({
  bars,
  boardSlug,
  isAdmin,
  language,
  translationEnabled,
  onDatesChanged,
}: GanttChartProps) {
  const t = useT('roadmapPage')

  if (bars.length === 0) {
    return (
      <div className="px-4 py-3 sm:px-5">
        <EmptyState
          kind="no-ideas"
          testId="roadmap-gantt-empty"
          title={t('ganttEmptyTitle')}
          description={t('ganttEmptyDescription')}
        />
      </div>
    )
  }

  const starts = bars.map((b) => toEpochDay(b.startDate))
  const knownEnds = bars
    .filter((b) => b.endDate !== null)
    .map((b) => toEpochDay(b.endDate as string))
  const todayEpoch = toEpochDay(dateOnly(new Date().toISOString()))
  const rawMin = Math.min(...starts)
  const rawMax = Math.max(...knownEnds, todayEpoch, ...starts)
  const pad = DAY_MS * 2
  const timelineMin = rawMin - pad
  const timelineMax = Math.max(rawMax + pad, timelineMin + pad * 2)
  const months = monthTicks(timelineMin, timelineMax, language)
  const quarters = quarterTicks(months)

  return (
    <div className="animate-vp-fade-in">
      {/* Axis header: a quarter/year row above a month row, both positioned
          as clipped percentages of the same track the bars sit in — the
          label column stays blank so the axis lines up with the track, not
          the idea title. */}
      <div className="flex items-stretch gap-3 px-4 pt-4 sm:gap-4 sm:px-5">
        <div
          className={cx('hidden w-full shrink-0 sm:block', GANTT_LABEL_COL)}
          aria-hidden="true"
        />
        <div className="min-w-0 flex-1" aria-hidden="true">
          <div className="relative h-5 border-b border-vp-rule">
            {quarters.map((q) => (
              <div
                key={q.key}
                className="absolute inset-y-0 flex items-center overflow-hidden border-l border-vp-rule pl-1.5 text-vp-micro font-semibold uppercase tracking-wide text-vp-text-muted first:border-l-0"
                style={{ left: `${q.leftPct}%`, width: `${q.widthPct}%` }}
              >
                {q.widthPct >= 5 && <span className="truncate">{q.label}</span>}
              </div>
            ))}
          </div>
          <div className="relative h-6 pb-2">
            {months.map((m) => (
              <div
                key={m.key}
                className="absolute inset-y-0 flex items-center overflow-hidden border-l border-vp-rule pl-1.5 text-vp-xs text-vp-text-muted first:border-l-0"
                style={{ left: `${m.leftPct}%`, width: `${m.widthPct}%` }}
              >
                {m.widthPct >= 5 && <span className="truncate">{m.label}</span>}
              </div>
            ))}
          </div>
        </div>
        {isAdmin && <div className="hidden w-8 shrink-0 sm:block" aria-hidden="true" />}
      </div>
      <div role="list" aria-label={t('ganttAriaLabel')} className="divide-y divide-vp-rule">
        {bars.map((bar) => (
          <div key={bar.idea.id} role="listitem">
            <GanttRow
              bar={bar}
              boardSlug={boardSlug}
              isAdmin={isAdmin}
              language={language}
              translationEnabled={translationEnabled}
              timelineMin={timelineMin}
              timelineMax={timelineMax}
              monthTicks={months}
              onDatesChanged={onDatesChanged}
            />
          </div>
        ))}
      </div>
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; notFound: boolean; message: string }
  | { phase: 'done'; data: RoadmapResponse }

export default function RoadmapPage() {
  const resolvedTheme = useResolvedTheme()
  const t = useT('roadmapPage')
  const tCommon = useT('common')
  const online = useIsOnline()
  const { language } = useI18n()
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [view, setView] = useState<ViewMode>('list')
  // Once the board turns out to allow the Gantt view, it becomes the default
  // — but only the FIRST time (a manual switch away must stick), and never
  // overriding a view the user already picked before the response arrived.
  const hasUserPickedView = useRef(false)
  const [viewAsVoter, setViewAsVoterPreview] = useVoterPreview()
  const isPhone = useIsPhone()
  // idea-comment-translation — bootstrap()'s fail-secure flag, same source as
  // BoardPage's/IdeaDetailPage's translationAvailable.
  const [translationAvailable, setTranslationAvailable] = useState(false)

  // Seed CSRF + auth state on mount.
  useEffect(() => {
    bootstrap()
      .then((b) => {
        setIsAuthenticated(b.user !== null)
        setUser(b.user)
        setTranslationAvailable(b.translation_available ?? false)
      })
      .catch(() => {})
  }, [])

  const loadRoadmap = useCallback(() => {
    if (!boardSlug) return

    setLoadState({ phase: 'loading' })

    getRoadmap(boardSlug)
      .then((data) => setLoadState({ phase: 'done', data }))
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const notFound = apiErr.name === 'ApiError' && apiErr.status === 404
        setLoadState({
          phase: 'error',
          notFound,
          message: notFound ? t('errorNotFound') : t('errorLoadFailed'),
        })
      })
  }, [boardSlug, t])

  useEffect(() => {
    loadRoadmap()
  }, [loadRoadmap])

  useEffect(() => {
    if (loadState.phase !== 'done' || hasUserPickedView.current) return
    if (loadState.data.roadmap_gantt_allowed) setView('gantt')
  }, [loadState])

  // Applies a Gantt date edit (optimistic write from GanttRow, or its
  // rollback on error) directly onto the loaded groups, in place.
  const updateIdeaDates = useCallback(
    (ideaId: number, startDate: string | null, targetDate: string | null) => {
      setLoadState((prev) => {
        if (prev.phase !== 'done') return prev
        const patch = (list: RoadmapIdea[]) =>
          list.map((idea) =>
            idea.id === ideaId ? { ...idea, start_date: startDate, target_date: targetDate } : idea,
          )
        return {
          ...prev,
          data: {
            ...prev.data,
            groups: {
              planned: patch(prev.data.groups.planned),
              in_progress: patch(prev.data.groups.in_progress),
              done: patch(prev.data.groups.done),
            },
          },
        }
      })
    },
    [],
  )

  const handleLogout = () => {
    logout()
      .catch(() => {})
      .finally(() => {
        setIsAuthenticated(false)
        setUser(null)
        navigate('/login')
      })
  }

  const basePath = boardSlug ? accountPath(`/${boardSlug}`) : ''
  const boardName = loadState.phase === 'done' ? loadState.data.board.name : undefined
  const brandLogoUrl =
    loadState.phase === 'done' ? boardLogoSrc(boardSlug, loadState.data.board) : undefined

  const header = (
    <LocalizedHeader
      logoHref={basePath || accountPath('/')}
      basePath={basePath}
      boardSlug={boardSlug}
      boardTabsInPage
      isAuthenticated={isAuthenticated}
      user={user}
      onLoginClick={() =>
        navigate(boardSlug ? `/login?r=${encodeURIComponent(basePath)}` : '/login')
      }
      onLogoutClick={handleLogout}
      scope={<ScopeLabel section={boardName} />}
      voterPreview={viewAsVoter}
      onVoterPreviewChange={setViewAsVoterPreview}
      brandLogoUrl={brandLogoUrl}
      brandWordmarkUrl={
        loadState.phase === 'done' ? boardWordmarkSrc(boardSlug, loadState.data.board) : undefined
      }
      brandWordmarkPlated={
        loadState.phase === 'done' ? boardWordmarkPlated(loadState.data.board) : false
      }
      brandInitialsName={
        loadState.phase === 'done' ? boardInitialsName(loadState.data.board) : undefined
      }
      brandAccent={
        loadState.phase === 'done' ? (loadState.data.board.primary_color ?? undefined) : undefined
      }
      brandLogoAlt={boardName}
      brandLogoPending={loadState.phase !== 'done'}
    />
  )

  if (loadState.phase === 'loading') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {/* Mirrors the loaded page: masthead on the paper with the view switch, then three sheets. */}
        <div className="mb-8 flex flex-wrap items-end justify-between gap-4" aria-hidden="true">
          <div className="flex flex-col gap-3">
            <span aria-hidden="true" className="ds-skeleton__block h-3 w-24" />
            <span aria-hidden="true" className="ds-skeleton__block h-11 w-56 max-w-full" />
            <span aria-hidden="true" className="ds-skeleton__block h-5 w-80 max-w-full" />
          </div>
          <span aria-hidden="true" className="ds-skeleton__block h-8 w-40" />
        </div>
        <div className="flex flex-col gap-6">
          {SECTIONS.map(({ key }, i) => (
            <div key={key} className="vp-sheet px-3 sm:px-4">
              {i === 0 ? (
                <LoadingState label={t('loading')} rows={3} />
              ) : (
                <div className="py-2" aria-hidden="true">
                  <Skeleton
                    lines={2}
                    label={t('loading')}
                    testId={`roadmap-loading-sheet-${key}`}
                  />
                </div>
              )}
            </div>
          ))}
        </div>
      </PageShell>
    )
  }

  if (loadState.phase === 'error') {
    // Unknown board: the shared not-found page (no identifiers, nothing that reveals existence).
    if (loadState.notFound) return <NotFoundState />
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <ErrorState
          title={t('errorHeading')}
          description={loadState.message}
          action={
            <Button type="button" variant="secondary" testId="roadmap-retry" onClick={loadRoadmap}>
              {t('retry')}
            </Button>
          }
        />
      </PageShell>
    )
  }

  const { board, groups, roadmap_gantt_allowed: ganttAllowed } = loadState.data
  // Owner-renamed/custom statuses (issue 03): labels come from the board's
  // registry, the column stays the category. A column whose statuses are
  // all hidden disappears — unless ideas still sit in it.
  const statusDefaults: Record<IdeaStatus, string> = {
    open: t('sectionOpen'),
    planned: t('sectionPlanned'),
    in_progress: t('sectionInProgress'),
    done: t('sectionDone'),
    declined: t('sectionDeclined'),
    archived: t('sectionArchived'),
  }
  const sectionLabel = (key: IdeaStatus) =>
    statusLabel(board.statuses, key, key, language, statusDefaults)
  const statusLabelFor = (idea: RoadmapIdea) =>
    statusLabel(board.statuses, idea.status_key, idea.status, language, statusDefaults)
  const visibleSections = SECTIONS.filter(({ key }) => {
    const inCategory = (board.statuses ?? []).filter((s) => s.category === key)
    return inCategory.length === 0 || inCategory.some((s) => !s.is_hidden) || groups[key].length > 0
  })
  const translationEnabled = translationAvailable && isAuthenticated && board.translation_enabled
  const role = accountRoleFor(user, getAccountSlug())
  const isBoardAdmin =
    !viewAsVoter && user !== null && (user.is_admin || (role !== null && role !== 'member'))

  const viewItems = [
    { value: 'list' as const, label: t('viewList') },
    { value: 'columns' as const, label: t('viewColumns') },
    ...(ganttAllowed ? [{ value: 'gantt' as const, label: t('viewGantt') }] : []),
  ]

  const ganttBars: GanttBar[] = [
    ...groups.planned.map((idea) => ({ idea, status: 'planned' as const })),
    ...groups.in_progress.map((idea) => ({ idea, status: 'in-progress' as const })),
    ...groups.done.map((idea) => ({ idea, status: 'done' as const })),
  ].map(({ idea, status }) => ({
    idea,
    status,
    startDate: dateOnly(idea.start_date ?? idea.created_at),
    endDate: idea.target_date ? dateOnly(idea.target_date) : null,
  }))

  return (
    <div style={brandingStyle(board, resolvedTheme)} {...brandingProAttributes(board.branding_pro)}>
      <BoardThemeMode mode={board.theme_mode} boardSlug={board.slug} />
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {!online && (
          <InlineNotice tone="warning" className="mb-4" role="status">
            {tCommon('state.offline')}
          </InlineNotice>
        )}
        {/* Masthead: the board's profile header, the same as the board page (cover band,
          round avatar, name eyebrow), then the roadmap title, lead and view switch. */}
        <header className="mb-8 md:mb-10 animate-vp-rise" data-testid="roadmap-profile">
          <BoardCoverBand
            slug={board.slug}
            seed={board.id}
            accent={coverAccentFor(board, resolvedTheme === 'dark')}
            surface={board.secondary_color}
            dark={resolvedTheme === 'dark'}
            coverPattern={board.cover_pattern}
            assets={board.assets}
          />
          <div className="flex flex-wrap items-end justify-between gap-x-8 gap-y-5">
            <div className="ds-board-profile min-w-0 flex-1 basis-full sm:basis-auto">
              <div className="ds-board-profile__avatar relative -top-6 md:-top-10">
                <Avatar
                  size="large"
                  name={board.name}
                  color={board.primary_color}
                  testId="board-profile-logo"
                  src={boardLogoSrc(board.slug, board) ?? null}
                />
              </div>
              <div className="min-w-0">
                <div className="vp-eyebrow mb-3 max-sm:hidden">{board.name}</div>
                {board.primary_color && (
                  <div
                    aria-hidden="true"
                    className="mb-3 h-1.5 w-12 rounded-vp-full bg-vp-accent max-sm:hidden"
                  />
                )}
                <h1 className="font-archivo font-extrabold text-vp-4xl md:text-vp-display leading-vp-display tracking-vp-display text-vp-title text-balance">
                  {t('heading')}
                </h1>
                <p className="mt-4 max-w-vp-measure text-vp-lg md:text-vp-xl leading-7 md:leading-8 text-vp-text-secondary text-pretty">
                  {t('subtitle', { name: board.name })}
                </p>
              </div>
            </div>
            <div className="w-full sm:w-auto max-sm:[&_button]:min-h-11">
              <Tabs
                items={viewItems}
                value={view}
                onChange={(next) => {
                  hasUserPickedView.current = true
                  setView(next)
                }}
                ariaLabel={t('viewAriaLabel')}
                testIdPrefix="roadmap-view"
              />
            </div>
          </div>
          {!isPhone && (
            <BoardProfileTabs
              basePath={basePath}
              current="roadmap"
              showRoadmap={boardRoadmapVisible(
                user,
                viewAsVoter,
                board.branding_pro?.roadmap_hidden,
              )}
            />
          )}
        </header>

        {view === 'list' ? (
          <div className="flex flex-col gap-6" key="list">
            {visibleSections.map(({ key, status }) => {
              const ideas = groups[key]
              return (
                <Section
                  testId="roadmap-list-section"
                  key={key}
                  title={<SectionTitle status={status} label={sectionLabel(key)} />}
                  actions={<SectionCount count={ideas.length} />}
                  flush
                >
                  {ideas.length === 0 ? (
                    <SectionEmpty sectionKey={key} />
                  ) : (
                    <IdeaList
                      ideas={ideas}
                      boardSlug={board.slug}
                      translationEnabled={translationEnabled}
                      language={language}
                      statusLabelFor={statusLabelFor}
                      brandingPro={board.branding_pro}
                    />
                  )}
                </Section>
              )
            })}
          </div>
        ) : view === 'columns' ? (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 items-start" key="columns">
            {visibleSections.map(({ key, status }) => {
              const ideas = groups[key]
              return (
                <Section
                  testId="roadmap-column-section"
                  key={key}
                  title={<SectionTitle status={status} label={sectionLabel(key)} />}
                  actions={<SectionCount count={ideas.length} />}
                  flush
                >
                  {ideas.length === 0 ? (
                    <SectionEmpty sectionKey={key} />
                  ) : (
                    <ul
                      className="ds-section-list ds-section-list--bare animate-vp-fade-in"
                      aria-label={sectionLabel(key)}
                    >
                      {ideas.map((idea) => (
                        <li key={idea.id}>
                          <RoadmapIdeaCard
                            idea={idea}
                            boardSlug={board.slug}
                            translationEnabled={translationEnabled}
                            language={language}
                            statusLabel={statusLabelFor(idea)}
                            brandingPro={board.branding_pro}
                          />
                        </li>
                      ))}
                    </ul>
                  )}
                </Section>
              )
            })}
          </div>
        ) : (
          <Section testId="roadmap-gantt-section" key="gantt" flush>
            <GanttChart
              bars={ganttBars}
              boardSlug={board.slug}
              isAdmin={isBoardAdmin}
              language={language}
              translationEnabled={translationEnabled}
              onDatesChanged={updateIdeaDates}
            />
          </Section>
        )}
        <BoardBrandFooter slug={board.slug} legalOperatorName={null} visual={board} />
      </PageShell>
    </div>
  )
}

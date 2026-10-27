/**
 * WorklistPage — /admin/worklist (P-06).
 *
 * One account-wide list of the ideas that wait for a decision or an answer,
 * with the three header counts from the same response as the rows (so the
 * header and the list never disagree). Filters: reason, age, board and sort;
 * "load more" follows the opaque cursor.
 *
 * Row actions (slice 04, part B) live in WorklistRow and call the existing
 * endpoints. A row that succeeded leaves the list: this page removes it, moves
 * focus to the next row (or the empty region), reloads the counts and notifies
 * the nav badge. A dismissed row ("Ausblenden") shows an undo notice until the
 * next dismissal or undo.
 *
 * Auth gate: anon → login; no owner/admin/moderator role → "no access" (the
 * backend answers 403 on GET {accountPrefix}/admin/worklist, AuthZ
 * accountModerate). Fail-secure: when the list fails, no counts and no rows
 * are shown, only the error with a retry.
 */

import {
  Button,
  ButtonLink,
  ChipToggle,
  EmptyState,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  Section,
  Select,
  StatCard,
} from '@votepit/ui/ds'
import type { ReactNode } from 'react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { RouterAnchor, WorklistRow, type WorklistRowExit } from '../components/WorklistRow'
import { accountPath } from '../lib/accountContext'
import type {
  AdminBoardSummary,
  ApiError,
  User,
  WorklistAge,
  WorklistCategory,
  WorklistCounts,
  WorklistIdea,
  WorklistSort,
} from '../lib/api'
import {
  bootstrap,
  getWorklist,
  getWorklistSummary,
  listAdminBoards,
  logout,
  notifyWorklistChanged,
  restoreWorklistIdea,
} from '../lib/api'
import { formatDateTime, intlLocale } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

interface Filters {
  category: WorklistCategory
  age: WorklistAge
  sort: WorklistSort
  /** Board slug; empty string = all boards. */
  board: string
}

const DEFAULT_FILTERS: Filters = { category: 'all', age: 'all', sort: 'oldest', board: '' }

interface ListData {
  counts: WorklistCounts
  ideas: WorklistIdea[]
  nextCursor: string | null
  checkedAt: Date
}

/** A dismissed row kept for the undo notice, with its position in the list. */
interface Dismissed {
  idea: WorklistIdea
  index: number
}

type ListStatus = { kind: 'busy' } | { kind: 'error'; message: string } | { kind: 'ok' }

type PageStatus = 'loading' | 'denied' | 'loaded'

/** Reason chips: category value, test-id suffix and label key. */
const REASON_CHIPS: { value: WorklistCategory; key: string; label: string }[] = [
  { value: 'all', key: 'all', label: 'reasonAll' },
  { value: 'unanswered', key: 'unanswered', label: 'reasonUnanswered' },
  { value: 'pending_moderation', key: 'pending-moderation', label: 'reasonPendingModeration' },
  { value: 'decision_due', key: 'decision-due', label: 'reasonDecisionDue' },
]

export default function WorklistPage() {
  const navigate = useNavigate()
  const t = useT('worklistPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const locale = intlLocale(language)

  const [pageStatus, setPageStatus] = useState<PageStatus>('loading')
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [boards, setBoards] = useState<AdminBoardSummary[] | null>(null)
  const [filters, setFilters] = useState<Filters>(DEFAULT_FILTERS)
  const [data, setData] = useState<ListData | null>(null)
  const [listStatus, setListStatus] = useState<ListStatus>({ kind: 'busy' })
  const [moreBusy, setMoreBusy] = useState(false)
  const [moreError, setMoreError] = useState(false)
  const [announce, setAnnounce] = useState('')
  const [undo, setUndo] = useState<Dismissed | null>(null)
  const [undoBusy, setUndoBusy] = useState(false)
  const [undoError, setUndoError] = useState<string | null>(null)
  /** Bumped on every list request; a response for an older request is dropped. */
  const requestSeq = useRef(0)
  /** Mirror of `data` for row callbacks that run after a re-render. */
  const dataRef = useRef<ListData | null>(null)
  /** Where focus goes after a row left the list: a row id, or the list region when none is left. */
  const focusTarget = useRef<number | 'region' | null>(null)
  const listRegionRef = useRef<HTMLDivElement>(null)

  const fmt = (n: number) => new Intl.NumberFormat(locale).format(n)

  const toQuery = useCallback(
    (f: Filters, cursor: string | null) => ({
      category: f.category,
      age: f.age,
      sort: f.sort,
      board: f.board === '' ? null : f.board,
      cursor,
    }),
    [],
  )

  const loadList = useCallback(
    async (next: Filters, source: 'filter' | 'retry') => {
      const seq = ++requestSeq.current
      setListStatus({ kind: 'busy' })
      setMoreError(false)
      try {
        const res = await getWorklist(toQuery(next, null))
        if (seq !== requestSeq.current) return
        setData({
          counts: res.counts,
          ideas: res.ideas,
          nextCursor: res.next_cursor,
          checkedAt: new Date(),
        })
        setListStatus({ kind: 'ok' })
        if (source === 'filter') setAnnounce(t('announceResults', { count: res.ideas.length }))
      } catch (err) {
        if (seq !== requestSeq.current) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/worklist'))}`, {
            replace: true,
          })
          return
        }
        setData(null)
        setListStatus({ kind: 'error', message: apiErr?.payload?.message ?? t('loadError') })
      }
    },
    [navigate, t, toQuery],
  )

  // biome-ignore lint/correctness/useExhaustiveDependencies: runs once on mount; loadList is stable per render only through its deps.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/worklist'))}`, {
            replace: true,
          })
          return
        }

        setIsAuthenticated(true)
        setUser(boot.user)

        const seq = ++requestSeq.current
        const [boardList, res] = await Promise.all([
          listAdminBoards().catch(() => null),
          getWorklist(toQuery(DEFAULT_FILTERS, null)).then(
            (value) => ({ ok: true as const, value }),
            (error: unknown) => ({ ok: false as const, error }),
          ),
        ])
        if (cancelled || seq !== requestSeq.current) return

        setBoards(boardList?.boards ?? null)
        if (res.ok) {
          setData({
            counts: res.value.counts,
            ideas: res.value.ideas,
            nextCursor: res.value.next_cursor,
            checkedAt: new Date(),
          })
          setListStatus({ kind: 'ok' })
        } else {
          const apiErr = res.error as ApiError
          if (apiErr?.name === 'ApiError' && apiErr.status === 403) {
            setPageStatus('denied')
            return
          }
          setListStatus({ kind: 'error', message: apiErr?.payload?.message ?? t('loadError') })
        }
        setPageStatus('loaded')
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/worklist'))}`, {
            replace: true,
          })
          return
        }
        setIsAuthenticated(true)
        setPageStatus('loaded')
        setListStatus({ kind: 'error', message: apiErr?.payload?.message ?? t('loadError') })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate])

  useEffect(() => {
    dataRef.current = data
  }, [data])

  // After a row left the list, focus the next row (or the list region when none is left).
  // `data` is the trigger: the effect runs once the list has re-rendered without the removed row.
  // biome-ignore lint/correctness/useExhaustiveDependencies: data is the intended trigger
  useEffect(() => {
    const target = focusTarget.current
    if (target === null) return
    focusTarget.current = null
    if (target !== 'region') {
      const link = document.querySelector<HTMLElement>(
        `[data-testid="worklist-row-${target}-link"]`,
      )
      if (link !== null) {
        link.focus()
        return
      }
    }
    listRegionRef.current?.focus()
  }, [data])

  const changeFilters = (patch: Partial<Filters>) => {
    const next = { ...filters, ...patch }
    if (JSON.stringify(next) === JSON.stringify(filters)) return
    setFilters(next)
    void loadList(next, 'filter')
  }

  const loadMore = async () => {
    if (listStatus.kind !== 'ok' || data === null || data.nextCursor === null || moreBusy) return
    const seq = requestSeq.current
    const cursor = data.nextCursor
    setMoreBusy(true)
    setMoreError(false)
    try {
      const res = await getWorklist(toQuery(filters, cursor))
      if (seq !== requestSeq.current) return
      setData({
        counts: res.counts,
        ideas: [...data.ideas, ...res.ideas],
        nextCursor: res.next_cursor,
        checkedAt: new Date(),
      })
    } catch {
      if (seq !== requestSeq.current) return
      setMoreError(true)
    } finally {
      setMoreBusy(false)
    }
  }

  /** Reloads the header counts after a row action (same board filter as the list). */
  const refreshCounts = async () => {
    try {
      const res = await getWorklistSummary(filters.board === '' ? null : filters.board)
      setData((prev) => (prev === null ? prev : { ...prev, counts: res.counts }))
    } catch {
      // Fail-secure: do not keep rows next to counts we could not confirm; reload the list (error state on failure).
      void loadList(filters, 'retry')
    }
  }

  /** A row's action succeeded: remove it, move focus, refresh counts and the badge. */
  const handleGone = (ideaId: number, exit: WorklistRowExit) => {
    const current = dataRef.current
    if (current === null) return
    const index = current.ideas.findIndex((idea) => idea.id === ideaId)
    if (index < 0) return
    const remaining = current.ideas.filter((idea) => idea.id !== ideaId)
    const next = remaining[index] ?? remaining[index - 1] ?? null
    focusTarget.current = next === null ? 'region' : next.id
    const updated = { ...current, ideas: remaining }
    dataRef.current = updated
    setData(updated)
    if (exit === 'dismissed') {
      setUndo({ idea: current.ideas[index], index })
      setUndoError(null)
      setAnnounce(t('announceHidden'))
    } else {
      setAnnounce(t('announceDone'))
    }
    notifyWorklistChanged()
    void refreshCounts()
  }

  /** Undo of a dismissal: the idea goes back to its old position if it still waits. */
  const undoDismiss = async () => {
    if (undo === null || undoBusy) return
    const { idea, index } = undo
    setUndoBusy(true)
    setUndoError(null)
    try {
      await restoreWorklistIdea(idea.id)
      setUndo(null)
      setData((prev) => {
        if (prev === null || prev.ideas.some((row) => row.id === idea.id)) return prev
        const ideas = [...prev.ideas]
        ideas.splice(Math.min(index, ideas.length), 0, idea)
        return { ...prev, ideas }
      })
      setAnnounce(t('announceRestored'))
      notifyWorklistChanged()
      void refreshCounts()
    } catch (err) {
      const apiErr = err as ApiError
      setUndoError(apiErr?.payload?.message ?? t('undoFailed'))
    } finally {
      setUndoBusy(false)
    }
  }

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const frame = (children: ReactNode) => (
    <AdminShell
      user={user}
      isAuthenticated={isAuthenticated}
      authPending={pageStatus === 'loading'}
      onLogout={handleLogout}
      onLogin={() => navigate('/login')}
    >
      {children}
    </AdminShell>
  )

  if (pageStatus === 'loading') {
    return frame(<LoadingState label={t('loading')} rows={5} testId="worklist-loading" />)
  }

  if (pageStatus === 'denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        testId="worklist-denied"
        action={
          <Button testId="worklist-denied-logout" variant="secondary" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  const filtersActive = filters.category !== 'all' || filters.age !== 'all' || filters.board !== ''
  const anyFilterSet = filtersActive || filters.sort !== 'oldest'
  const boardOptions = boards ?? []

  let listContent: ReactNode
  if (listStatus.kind === 'busy') {
    listContent = <LoadingState label={t('loading')} rows={4} testId="worklist-loading-list" />
  } else if (listStatus.kind === 'error') {
    listContent = (
      <ErrorState
        title={tCommon('state.errorTitle')}
        description={listStatus.message}
        testId="worklist-error"
        action={
          <Button
            testId="worklist-error-retry"
            variant="secondary"
            onClick={() => void loadList(filters, 'retry')}
          >
            {tCommon('state.retry')}
          </Button>
        }
      />
    )
  } else if (data !== null && data.ideas.length === 0) {
    if (boards !== null && boards.length === 0) {
      listContent = (
        <EmptyState
          kind="no-ideas"
          testId="worklist-empty"
          title={t('noBoardsTitle')}
          action={
            <ButtonLink
              href={accountPath('/admin/boards')}
              linkAs={RouterAnchor}
              testId="worklist-empty-create-board"
            >
              {t('noBoardsCreate')}
            </ButtonLink>
          }
        />
      )
    } else if (filtersActive) {
      listContent = (
        <EmptyState
          kind="no-results"
          testId="worklist-empty"
          title={t('emptyFilteredTitle')}
          action={
            <Button
              testId="worklist-empty-reset"
              variant="secondary"
              onClick={() => changeFilters(DEFAULT_FILTERS)}
            >
              {t('resetFilters')}
            </Button>
          }
        />
      )
    } else {
      listContent = (
        <EmptyState
          kind="no-ideas"
          testId="worklist-empty"
          title={t('emptyNothingTitle')}
          description={t('emptyChecked', {
            time: formatDateTime(data.checkedAt.toISOString(), language),
          })}
        />
      )
    }
  } else if (data !== null) {
    listContent = (
      <>
        <ul aria-label={t('listLabel')} data-testid="worklist-list" className="ds-section-list">
          {data.ideas.map((idea) => (
            <WorklistRow key={idea.id} idea={idea} onGone={handleGone} />
          ))}
        </ul>
        {data.nextCursor !== null && (
          <div className="ds-section-list__more">
            {moreError && (
              <InlineNotice tone="error" testId="worklist-load-more-error">
                {t('loadMoreError')}
              </InlineNotice>
            )}
            <Button
              variant="secondary"
              testId="worklist-load-more"
              loading={moreBusy}
              onClick={() => void loadMore()}
            >
              {t('loadMore')}
            </Button>
          </div>
        )}
      </>
    )
  }

  return frame(
    <div data-testid="worklist-page" className="flex min-w-0 flex-col gap-6">
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={<span data-testid="worklist-heading">{t('title')}</span>}
        description={t('subtitle')}
      />

      <div role="group" aria-label={t('filterGroupLabel')} className="flex flex-wrap gap-2">
        {REASON_CHIPS.map(({ value, key, label }) => (
          <ChipToggle
            key={key}
            testId={`worklist-filter-reason-${key}`}
            pressed={filters.category === value}
            onToggle={() => changeFilters({ category: value })}
          >
            {t(label)}
          </ChipToggle>
        ))}
        <ChipToggle
          testId="worklist-filter-age-all"
          pressed={filters.age === 'all'}
          onToggle={() => changeFilters({ age: 'all' })}
        >
          {t('ageAll')}
        </ChipToggle>
        <ChipToggle
          testId="worklist-filter-age-over-7-days"
          pressed={filters.age === 'over_7_days'}
          onToggle={() => changeFilters({ age: 'over_7_days' })}
        >
          {t('ageOver7')}
        </ChipToggle>
      </div>

      <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-3 min-[480px]:items-end">
        <Select
          label={t('boardLabel')}
          testId="worklist-filter-board"
          value={filters.board}
          onChange={(value) => changeFilters({ board: value })}
        >
          <option value="">{t('boardAll')}</option>
          {boardOptions.map((board) => (
            <option key={board.slug} value={board.slug}>
              {board.name}
            </option>
          ))}
        </Select>
        <Select
          label={t('sortLabel')}
          testId="worklist-filter-sort"
          value={filters.sort}
          onChange={(value) => changeFilters({ sort: value as WorklistSort })}
        >
          <option value="oldest">{t('sortOldest')}</option>
          <option value="votes">{t('sortVotes')}</option>
        </Select>
        {anyFilterSet && (
          <Button
            testId="worklist-filter-reset"
            variant="secondary"
            onClick={() => changeFilters(DEFAULT_FILTERS)}
          >
            {t('resetFilters')}
          </Button>
        )}
      </div>

      <p role="status" aria-live="polite" className="sr-only" data-testid="worklist-announce">
        {announce}
      </p>

      {data !== null && listStatus.kind !== 'error' && (
        <div
          role="group"
          aria-label={t('countsLabel')}
          className="grid grid-cols-1 gap-3 min-[360px]:grid-cols-3"
        >
          <StatCard
            testId="worklist-count-unanswered"
            label={t('countUnanswered')}
            value={fmt(data.counts.unanswered)}
            caption={t('countUnansweredOver7', { count: fmt(data.counts.unanswered_over_7_days) })}
          />
          <StatCard
            testId="worklist-count-pending-moderation"
            label={t('countPendingModeration')}
            value={fmt(data.counts.pending_moderation)}
          />
          <StatCard
            testId="worklist-count-decision-due"
            label={t('countDecisionDue')}
            value={fmt(data.counts.decision_due)}
          />
        </div>
      )}

      {undo !== null && (
        <InlineNotice
          tone="info"
          testId="worklist-undo-toast"
          action={
            <Button
              testId="worklist-undo"
              variant="secondary"
              loading={undoBusy}
              onClick={() => void undoDismiss()}
            >
              {t('undoButton')}
            </Button>
          }
        >
          {t('undoText', { title: undo.idea.title })}
          {undoError !== null && (
            <InlineNotice tone="error" testId="worklist-undo-error">
              {undoError}
            </InlineNotice>
          )}
        </InlineNotice>
      )}

      <Section testId="worklist-section" flush>
        <div ref={listRegionRef} tabIndex={-1} className="ds-section__focus-region">
          {listContent}
        </div>
      </Section>
    </div>,
  )
}

import type { SortValue, Status } from '@votepit/ui'
import {
  Button,
  buttonClassName,
  cx,
  EmptyState,
  ErrorState,
  FeaturedIdeaCard,
  IdeaListRow,
  LoadingState,
  PageShell,
  Pagination,
  Skeleton,
  SortTabs,
  StatusFilter,
  Toast,
} from '@votepit/ui'
import { Plus } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { LocalizedHeader, ScopeLabel } from '../components/LocalizedHeader'
import { useVote } from '../hooks/useVote'
import { accountPath } from '../lib/accountContext'
import type { ApiError, BoardResponse, Idea, User } from '../lib/api'
import { bootstrap, getBoard, getDefaultBoardSlug, logout } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Maps the backend status (underscore) to the component Status type (hyphen).
 * Backend: in_progress → Component: in-progress
 */
function toComponentStatus(raw: string): Status {
  if (raw === 'in_progress') return 'in-progress'
  const valid: Status[] = ['open', 'planned', 'in-progress', 'done', 'declined']
  return valid.includes(raw as Status) ? (raw as Status) : 'open'
}

/** Simple relative-time formatter (no i18n lib dependency). */
function formatTimeAgo(iso: string, t: ReturnType<typeof useT>): string {
  const created = new Date(iso.replace(' ', 'T'))
  const diffMs = Date.now() - created.getTime()
  const mins = Math.floor(diffMs / 60_000)
  if (mins < 2) return t('timeAgoJustNow')
  if (mins < 60) return t('timeAgoMinutes', { count: mins })
  const hours = Math.floor(mins / 60)
  if (hours < 24) return t('timeAgoHours', { count: hours })
  const days = Math.floor(hours / 24)
  if (days < 30) return t(days === 1 ? 'timeAgoDaySingular' : 'timeAgoDayPlural', { count: days })
  const months = Math.floor(days / 30)
  if (months < 12) {
    return t(months === 1 ? 'timeAgoMonthSingular' : 'timeAgoMonthPlural', { count: months })
  }
  const years = Math.floor(months / 12)
  return t(years === 1 ? 'timeAgoYearSingular' : 'timeAgoYearPlural', { count: years })
}

/** Consensus percentage from up/down counts. */
function calcConsensus(upCount: number, downCount: number): number | null {
  const total = upCount + downCount
  if (total === 0) return null
  return Math.round((upCount / total) * 100)
}

/** Clamp body to a short excerpt for list rows. */
function toExcerpt(body: string, maxChars = 120): string {
  if (body.length <= maxChars) return body
  return `${body.slice(0, maxChars).trimEnd()}…`
}

// ── Sort mapping ──────────────────────────────────────────────────────────────

function sortValueToApi(sv: SortValue): string {
  const map: Record<SortValue, string> = {
    top: 'top',
    newest: 'newest',
    controversial: 'newest', // no backend equivalent yet → fall back to newest
  }
  return map[sv]
}

/** Converts the component Status type (hyphen) to the backend allow-list value (underscore). */
function statusToApi(s: Status | null): string | undefined {
  if (s === null) return undefined
  if (s === 'in-progress') return 'in_progress'
  return s
}

/** Translation-dictionary keys (in `common`) per status, shared across StatusBadge/StatusFilter uses. */
const STATUS_LABEL_KEYS: Record<Status, string> = {
  open: 'status.open',
  planned: 'status.planned',
  'in-progress': 'status.inProgress',
  done: 'status.done',
  declined: 'status.declined',
}

// ── VotableRow ────────────────────────────────────────────────────────────────

interface VotableRowProps {
  idea: Idea
  boardSlug: string
  isAuthenticated: boolean
  /** Raised when the server refuses a vote and the mark rolls back. */
  onVoteError: () => void
}

function VotableRow({ idea, boardSlug, isAuthenticated, onVoteError }: VotableRowProps) {
  const t = useT('boardPage')
  const tCommon = useT('common')
  const voteResult = useVote({
    boardSlug,
    ideaId: idea.id,
    isAuthenticated,
    initialScore: idea.score_cache,
    initialMyVote: idea.my_vote,
    initialUpCount: idea.up_count,
    initialDownCount: idea.down_count,
    returnTo: `/${boardSlug}/idea/${idea.id}`,
    onError: onVoteError,
  })

  return (
    <IdeaListRow
      id={idea.id}
      title={idea.title}
      excerpt={toExcerpt(idea.body)}
      status={toComponentStatus(idea.status)}
      statusLabel={tCommon(STATUS_LABEL_KEYS[toComponentStatus(idea.status)])}
      score={voteResult.score}
      commentCount={idea.comment_count}
      timeAgo={formatTimeAgo(idea.created_at, t)}
      consensusPercent={calcConsensus(voteResult.upCount, voteResult.downCount)}
      userVote={voteResult.myVote}
      onVoteUp={voteResult.onVoteUp}
      onVoteDown={voteResult.onVoteDown}
      href={accountPath(`/${boardSlug}/idea/${idea.id}`)}
      commentLabel={tCommon('idea.commentLabel')}
      commentsLabel={tCommon('idea.commentsLabel')}
      consensusLabel={tCommon('vote.consensusLabel')}
      consensusLowLabel={tCommon('vote.consensusLowLabel')}
      consensusEmptyLabel={tCommon('vote.consensusEmptyLabel')}
      upAriaLabel={tCommon('vote.upAriaLabel')}
      downAriaLabel={tCommon('vote.downAriaLabel')}
      pinned={idea.is_pinned}
      pinnedLabel={tCommon('idea.pinnedLabel')}
    />
  )
}

// ── VotableFeatured ───────────────────────────────────────────────────────────

interface VotableFeaturedProps {
  idea: Idea
  boardSlug: string
  isAuthenticated: boolean
  stats: BoardResponse['stats']
  onVoteError: () => void
}

/**
 * The declared result at the head of the sheet. Its ballot is live, like every
 * other ballot on the page — the leading idea is the one people most want to
 * mark, so its boxes must actually cast a vote.
 */
function VotableFeatured({
  idea,
  boardSlug,
  isAuthenticated,
  stats,
  onVoteError,
}: VotableFeaturedProps) {
  const tCommon = useT('common')
  const voteResult = useVote({
    boardSlug,
    ideaId: idea.id,
    isAuthenticated,
    initialScore: idea.score_cache,
    initialMyVote: idea.my_vote,
    initialUpCount: idea.up_count,
    initialDownCount: idea.down_count,
    returnTo: `/${boardSlug}/idea/${idea.id}`,
    onError: onVoteError,
  })

  return (
    <FeaturedIdeaCard
      title={idea.title}
      description={toExcerpt(idea.body, 200)}
      status={toComponentStatus(idea.status)}
      statusLabel={tCommon(STATUS_LABEL_KEYS[toComponentStatus(idea.status)])}
      score={voteResult.score}
      commentCount={idea.comment_count}
      consensusPercent={calcConsensus(voteResult.upCount, voteResult.downCount)}
      userVote={voteResult.myVote}
      onVoteUp={voteResult.onVoteUp}
      onVoteDown={voteResult.onVoteDown}
      weeklyVotes={stats.weekly_votes}
      weeklyNewIdeas={stats.weekly_new_ideas}
      avgConsensusPercent={stats.avg_consensus}
      href={accountPath(`/${boardSlug}/idea/${idea.id}`)}
      topIdeaLabel={tCommon('idea.topIdeaLabel')}
      commentLabel={tCommon('idea.commentLabel')}
      commentsLabel={tCommon('idea.commentsLabel')}
      thisWeekLabel={tCommon('idea.thisWeekLabel')}
      votesGivenLabel={tCommon('idea.votesGivenLabel')}
      newIdeasLabel={tCommon('idea.newIdeasLabel')}
      avgConsensusLabel={tCommon('idea.avgConsensusLabel')}
      consensusLabel={tCommon('vote.consensusLabel')}
      consensusLowLabel={tCommon('vote.consensusLowLabel')}
      consensusEmptyLabel={tCommon('vote.consensusEmptyLabel')}
      upAriaLabel={tCommon('vote.upAriaLabel')}
      downAriaLabel={tCommon('vote.downAriaLabel')}
    />
  )
}

// ── Component ─────────────────────────────────────────────────────────────────

/**
 * `noBoard` is deliberately separate from `notFound`: nothing has been set up
 * yet (an empty state), rather than a request that failed (an error state).
 */
type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; kind: 'notFound' | 'noBoard' | 'failure' }
  | { phase: 'done'; data: BoardResponse }

export default function BoardPage() {
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()
  const t = useT('boardPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [sort, setSort] = useState<SortValue>('top')
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState<Status | null>(null)
  const [retryKey, setRetryKey] = useState(0)
  // A rejected vote rolls the mark back; the toast says why it snapped back.
  const [voteFailed, setVoteFailed] = useState(false)

  // Fetch bootstrap once on mount to seed CSRF token + auth state.
  useEffect(() => {
    bootstrap()
      .then((b) => {
        setIsAuthenticated(b.user !== null)
        setUser(b.user)
      })
      .catch(() => {
        // Bootstrap failure is non-fatal — continue without auth context.
      })
  }, [])

  // Root route `/` has no :boardSlug yet — resolve the account's default board and
  // redirect there. Without this, the effect below would return early forever and the
  // page would stay stuck on the loading state.
  useEffect(() => {
    if (boardSlug) return

    getDefaultBoardSlug()
      .then(({ slug }) => navigate(accountPath(`/${slug}`), { replace: true }))
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const noBoard = apiErr.name === 'ApiError' && apiErr.status === 404
        setLoadState({ phase: 'error', kind: noBoard ? 'noBoard' : 'failure' })
      })
  }, [boardSlug, navigate])

  // Fetch board data whenever slug / sort / page changes (or a retry is requested).
  // biome-ignore lint/correctness/useExhaustiveDependencies: retryKey is a deliberate re-run trigger.
  useEffect(() => {
    if (!boardSlug) return

    setLoadState({ phase: 'loading' })

    getBoard(boardSlug, { sort: sortValueToApi(sort), status: statusToApi(status), page })
      .then((data) => {
        setIsAuthenticated(data.is_authenticated)
        setLoadState({ phase: 'done', data })
      })
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const notFound = apiErr.name === 'ApiError' && apiErr.status === 404
        setLoadState({ phase: 'error', kind: notFound ? 'notFound' : 'failure' })
      })
  }, [boardSlug, sort, status, page, retryKey])

  const handleSortChange = (newSort: SortValue) => {
    setSort(newSort)
    setPage(1)
  }

  const handleStatusChange = (newStatus: Status | null) => {
    setStatus(newStatus)
    setPage(1)
    // sort is intentionally preserved — invariant: sort survives filter changes
  }

  const handlePageChange = (newPage: number) => {
    setPage(newPage)
    // Respect prefers-reduced-motion — the smooth scroll is decoration, the jump is not.
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' })
  }

  const handleLogout = () => {
    logout()
      .catch(() => {
        // Even if logout request fails, navigate to login page.
      })
      .finally(() => {
        setIsAuthenticated(false)
        setUser(null)
        navigate('/login')
      })
  }

  const handleLogin = useCallback(() => {
    navigate(boardSlug ? `/login?r=${encodeURIComponent(accountPath(`/${boardSlug}`))}` : '/login')
  }, [boardSlug, navigate])

  // ── Render ──────────────────────────────────────────────────────────────────

  const boardName = loadState.phase === 'done' ? loadState.data.board.name : undefined

  const header = (
    <LocalizedHeader
      logoHref={accountPath(boardSlug ? `/${boardSlug}` : '/')}
      basePath={boardSlug ? accountPath(`/${boardSlug}`) : ''}
      boardSlug={boardSlug}
      isAuthenticated={isAuthenticated}
      user={user}
      onLoginClick={handleLogin}
      onLogoutClick={handleLogout}
      scope={<ScopeLabel section={boardName} />}
    />
  )

  if (loadState.phase === 'loading') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {/* Mirrors the loaded page: masthead card, control row, then the ruled sheet of ballot lines. */}
        <div className="vp-card vp-sheet--accent mb-6" aria-hidden="true">
          <div className="flex flex-col gap-3 p-5 sm:p-7">
            <Skeleton className="h-3 w-16" />
            <Skeleton className="h-9 w-64 max-w-full" />
            <Skeleton className="h-4 w-96 max-w-full" />
          </div>
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-vp-border-subtle px-5 sm:px-7 py-3">
            <div className="flex flex-wrap gap-1.5">
              <Skeleton className="h-8 w-14" />
              <Skeleton className="h-8 w-20" />
              <Skeleton className="h-8 w-24" />
              <Skeleton className="h-8 w-24" />
            </div>
            <Skeleton className="h-8 w-48" />
          </div>
        </div>
        <div className="vp-sheet vp-sheet--ruled px-3 sm:px-4">
          <LoadingState label={t('loading')} rows={6} variant="ballot" />
        </div>
      </PageShell>
    )
  }

  if (loadState.phase === 'error') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {loadState.kind === 'noBoard' ? (
          // Nothing has been set up yet — an empty result sheet, not a failure.
          <div className="vp-sheet vp-sheet--ruled">
            <EmptyState title={t('noBoardTitle')} description={t('noBoardConfigured')} />
          </div>
        ) : loadState.kind === 'notFound' ? (
          <ErrorState
            role="status"
            title={t('boardNotFoundTitle')}
            description={t('boardNotFound')}
            action={
              <Link to={accountPath('/')} className={buttonClassName('secondary')}>
                {t('backHome')}
              </Link>
            }
          />
        ) : (
          <ErrorState
            title={t('loadErrorTitle')}
            description={t('loadError')}
            action={
              <Button variant="secondary" onClick={() => setRetryKey((k) => k + 1)}>
                {tCommon('state.retry')}
              </Button>
            }
          />
        )}
      </PageShell>
    )
  }

  const { board, ideas, total_pages, stats } = loadState.data

  // Featured top idea: only in the canonical board-home state (page 1, default
  // sort, no status filter). Once the user sorts/filters/paginates, drop the hero
  // and show a plain list. The featured idea is excluded from the list below so it
  // is never shown twice.
  const showFeatured = page === 1 && status === null && sort === 'top' && ideas.length > 0
  const topIdea: Idea | undefined = showFeatured ? ideas[0] : undefined
  const listIdeas: Idea[] = showFeatured ? ideas.slice(1) : ideas

  const submitHref = accountPath(`/${board.slug}/submit`)

  return (
    <PageShell header={header} legalLinks={legalLinksFor(language)}>
      {/* Masthead: the board's own head — the tenant colour as a thin top edge,
          the name at display weight, and the control row on its foot rule. */}
      <div className="vp-card vp-sheet--accent mb-6 animate-vp-rise">
        <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:p-7">
          <div className="min-w-0 flex-1">
            <div className="vp-eyebrow mb-2">{tCommon('header.board')}</div>
            <h1 className="font-archivo font-bold text-vp-3xl md:text-vp-4xl tracking-[-0.025em] text-vp-ink text-balance">
              {board.name}
            </h1>
            {/* The intro is tenant-authored plaintext — rendered through .vp-prose
                (pre-wrap, break-anywhere), never as markup. */}
            {board.intro && (
              <p className="vp-prose mt-2 max-w-prose text-vp-md md:text-vp-lg text-vp-text-secondary text-pretty">
                {board.intro}
              </p>
            )}
          </div>
          <Link
            to={submitHref}
            className={buttonClassName('primary', 'lg', 'w-full sm:w-auto shrink-0')}
          >
            <Plus size={16} strokeWidth={2} aria-hidden="true" />
            {t('newIdea')}
          </Link>
        </div>
        {/* Controls: status chips + sort — one row on desktop, stacked on mobile */}
        <div className="flex flex-col gap-3 border-t border-vp-border-subtle px-5 sm:px-7 py-3 sm:flex-row sm:items-center sm:justify-between">
          <StatusFilter
            value={status}
            onChange={handleStatusChange}
            ariaLabel={tCommon('status.filterAriaLabel')}
            allLabel={tCommon('status.all')}
            labels={{
              open: tCommon(STATUS_LABEL_KEYS.open),
              planned: tCommon(STATUS_LABEL_KEYS.planned),
              'in-progress': tCommon(STATUS_LABEL_KEYS['in-progress']),
              done: tCommon(STATUS_LABEL_KEYS.done),
              declined: tCommon(STATUS_LABEL_KEYS.declined),
            }}
          />
          <SortTabs
            value={sort}
            onChange={handleSortChange}
            ariaLabel={tCommon('sort.ariaLabel')}
            labels={{
              top: tCommon('sort.top'),
              newest: tCommon('sort.newest'),
              controversial: tCommon('sort.controversial'),
            }}
          />
        </div>
      </div>

      {/* Featured top idea (canonical board-home view) */}
      {topIdea && (
        <div className="mb-4" data-testid="featured-idea">
          <VotableFeatured
            idea={topIdea}
            boardSlug={board.slug}
            isAuthenticated={isAuthenticated}
            stats={stats}
            onVoteError={() => setVoteFailed(true)}
          />
        </div>
      )}

      {/* The result sheet: one ballot line per idea */}
      {ideas.length === 0 ? (
        <div className="vp-sheet vp-sheet--ruled">
          {status !== null ? (
            <EmptyState
              title={t('noIdeasForStatusTitle')}
              description={t('noIdeasForStatusDescription')}
              action={
                <Button variant="secondary" onClick={() => handleStatusChange(null)}>
                  {t('resetFilter')}
                </Button>
              }
            />
          ) : (
            <EmptyState
              title={t('noIdeasTitle')}
              description={t('noIdeasDescription')}
              action={
                <Link to={submitHref} className={buttonClassName('primary')}>
                  {t('submitFirstIdea')}
                </Link>
              }
            />
          )}
        </div>
      ) : (
        listIdeas.length > 0 && (
          <div
            // Re-keyed per query so a sort, filter or page change re-plays the
            // 180ms arrival fade instead of swapping rows in place.
            key={`${sort}-${status ?? 'all'}-${page}`}
            className={cx('vp-sheet animate-vp-fade-in', !showFeatured && 'vp-sheet--ruled')}
          >
            <div
              className="divide-y divide-vp-border-subtle"
              role="list"
              aria-label={t('ideasAriaLabel')}
            >
              {listIdeas.map((idea) => (
                <div key={idea.id} role="listitem">
                  <VotableRow
                    idea={idea}
                    boardSlug={board.slug}
                    isAuthenticated={isAuthenticated}
                    onVoteError={() => setVoteFailed(true)}
                  />
                </div>
              ))}
            </div>
            {total_pages > 1 && (
              <div className="border-t border-vp-border-subtle px-4 sm:px-5 py-3 bg-vp-surface-frost rounded-b-vp-lg">
                <Pagination
                  page={page}
                  totalPages={total_pages}
                  onChange={handlePageChange}
                  prevAriaLabel={tCommon('pagination.prevAriaLabel')}
                  nextAriaLabel={tCommon('pagination.nextAriaLabel')}
                  prevLabel={tCommon('pagination.prevLabel')}
                  nextLabel={tCommon('pagination.nextLabel')}
                  pageOfLabel={(p, total) => tCommon('pagination.pageOf', { page: p, total })}
                />
              </div>
            )}
          </div>
        )
      )}

      {/* "Powered by Votepit" badge (branding tiers): server has
          already re-checked the account's CURRENT plan before setting
          show_badge, so this component only needs to honour the flag. */}
      {board.show_badge && (
        <div className="mt-10 flex justify-center">
          <a
            href="https://votepit.com"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center rounded-vp-full border border-vp-border-subtle bg-vp-surface px-3 py-1 text-vp-xs text-vp-text-muted no-underline transition-colors hover:border-vp-rule hover:text-vp-ink"
          >
            {t('poweredBy')}
          </a>
        </div>
      )}

      {voteFailed && (
        <Toast
          type="error"
          message={tCommon('vote.failed')}
          onClose={() => setVoteFailed(false)}
          closeAriaLabel={tCommon('toast.closeAriaLabel')}
        />
      )}
    </PageShell>
  )
}

import type { SortValue, Status } from '@votepit/ui'
import {
  Button,
  buttonClassName,
  Celebration,
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
import { MarkdownLite } from '../components/MarkdownLite'
import { useBoardVotes } from '../hooks/useBoardVotes'
import { useFlipReorder } from '../hooks/useFlipReorder'
import type { VoteState } from '../hooks/useVote'
import { useVoterPreview } from '../hooks/useVoterPreview'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import type { ApiError, BoardResponse, Idea, User } from '../lib/api'
import { accountRoleFor, bootstrap, getBoard, getDefaultBoardSlug, logout } from '../lib/api'
import { brandingStyle } from '../lib/branding'
import { crossedThreshold, hasCelebrated, markCelebrated } from '../lib/celebrations'
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
  vote: VoteState
  onVoteUp: () => void
  onVoteDown: () => void
}

function VotableRow({ idea, boardSlug, vote, onVoteUp, onVoteDown }: VotableRowProps) {
  const t = useT('boardPage')
  const tCommon = useT('common')

  return (
    <IdeaListRow
      id={idea.id}
      title={idea.title}
      excerpt={<MarkdownLite text={idea.body} maxChars={120} />}
      status={toComponentStatus(idea.status)}
      statusLabel={tCommon(STATUS_LABEL_KEYS[toComponentStatus(idea.status)])}
      score={vote.score}
      commentCount={idea.comment_count}
      timeAgo={formatTimeAgo(idea.created_at, t)}
      consensusPercent={calcConsensus(vote.upCount, vote.downCount)}
      upCount={vote.upCount}
      downCount={vote.downCount}
      userVote={vote.myVote}
      onVoteUp={onVoteUp}
      onVoteDown={onVoteDown}
      href={accountPath(`/${boardSlug}/idea/${idea.id}`)}
      commentLabel={tCommon('idea.commentLabel')}
      commentsLabel={tCommon('idea.commentsLabel')}
      forLabel={tCommon('vote.upLabel')}
      againstLabel={tCommon('vote.downLabel')}
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
  stats: BoardResponse['stats']
  vote: VoteState
  onVoteUp: () => void
  onVoteDown: () => void
}

/**
 * The declared result at the head of the sheet. Its ballot is live, like every
 * other ballot on the page — the leading idea is the one people most want to
 * mark, so its boxes must actually cast a vote.
 */
function VotableFeatured({
  idea,
  boardSlug,
  stats,
  vote,
  onVoteUp,
  onVoteDown,
}: VotableFeaturedProps) {
  const t = useT('boardPage')
  const tCommon = useT('common')

  return (
    <FeaturedIdeaCard
      title={idea.title}
      description={<MarkdownLite text={idea.body} maxChars={200} />}
      status={toComponentStatus(idea.status)}
      statusLabel={tCommon(STATUS_LABEL_KEYS[toComponentStatus(idea.status)])}
      score={vote.score}
      commentCount={idea.comment_count}
      consensusPercent={calcConsensus(vote.upCount, vote.downCount)}
      upCount={vote.upCount}
      downCount={vote.downCount}
      timeAgo={formatTimeAgo(idea.created_at, t)}
      userVote={vote.myVote}
      onVoteUp={onVoteUp}
      onVoteDown={onVoteDown}
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
      forLabel={tCommon('vote.upLabel')}
      againstLabel={tCommon('vote.downLabel')}
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

/** Stable identity across renders — avoids re-seeding useBoardVotes on every render while loading. */
const EMPTY_IDEAS: Idea[] = []

/** How often to poll for other voters' activity while the tab is visible. */
const VOTE_POLL_INTERVAL_MS = 20_000

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

  // Owner/admin "view as voter" toggle — shared across every
  // board-scoped page (see useVoterPreview.ts) so it survives client-side
  // navigation to the roadmap, an idea, submit or edit without every link
  // needing to carry `?view=voter` itself.
  const [viewAsVoter, setViewAsVoterPreview] = useVoterPreview()

  // Board-owner momentum milestones — a small, curated set (first idea ever,
  // first idea from someone other than the owner, 10 ideas total),
  // deliberately kept short (see BoardPage's celebration effect below for
  // the "why these three" rationale) and celebrated with the sparkle effect,
  // never the confetti rain that's reserved for the referral reward.
  const [milestone, setMilestone] = useState<null | {
    kind: 'first-idea' | 'first-external' | 'ten-ideas'
  }>(null)

  // Shared, board-wide vote state (lifted out of individual rows) + the FLIP
  // reorder animation that plays whenever a score change re-sorts the list —
  // whether from the viewer's own click or a background poll of other
  // voters (see the poll effect below). The featured slot itself IS live
  // (see rankedIdeas below) — a vote can promote an idea straight into it;
  // only the FLIP animation across the hero/list-row boundary is out of
  // scope, so that specific transition just appears without animating.
  const boardIdeas = loadState.phase === 'done' ? loadState.data.ideas : EMPTY_IDEAS
  const { registerRow, captureBeforeReorder } = useFlipReorder<number>()
  const {
    get: getVote,
    castVote,
    mergeFromPoll,
  } = useBoardVotes(boardSlug ?? '', boardIdeas, isAuthenticated, captureBeforeReorder, () =>
    setVoteFailed(true),
  )

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

  // Poll for other voters' activity while the tab is visible — same board
  // query as the main fetch, but only the vote-related fields are merged in
  // (see useBoardVotes.mergeFromPoll), so it never disturbs pagination,
  // filters, or an in-flight optimistic vote of the viewer's own. Paused
  // whenever the tab is hidden to avoid pointless background load.
  useEffect(() => {
    if (!boardSlug) return

    const interval = setInterval(() => {
      if (document.hidden) return
      getBoard(boardSlug, { sort: sortValueToApi(sort), status: statusToApi(status), page })
        .then((data) => mergeFromPoll(data.ideas))
        .catch(() => {
          // A transient poll failure isn't worth surfacing — the next tick retries.
        })
    }, VOTE_POLL_INTERVAL_MS)

    return () => clearInterval(interval)
  }, [boardSlug, sort, status, page, mergeFromPoll])

  // Owner-only momentum milestones. Priority order matters: only the
  // FIRST milestone newly reached this tick is shown, never several
  // stacked at once. "first-external" is checked against whatever ideas
  // happen to be loaded on the current page/sort/filter — a best-effort
  // per-browser nicety, not a backend-verified fact, same tradeoff every
  // other localStorage-only celebration in this app already makes.
  useEffect(() => {
    if (loadState.phase !== 'done' || user === null) return
    const role = accountRoleFor(user, getAccountSlug())
    const isBoardOwner = user.is_admin || role === 'owner'
    if (!isBoardOwner) return

    const { board, ideas, stats } = loadState.data

    if (crossedThreshold(`board:${board.slug}:total-ideas:first`, 1, stats.total_ideas)) {
      setMilestone({ kind: 'first-idea' })
      return
    }

    const externalKey = `board:${board.slug}:first-external-idea`
    if (!hasCelebrated(externalKey) && ideas.some((idea) => idea.author_id !== user.id)) {
      markCelebrated(externalKey)
      setMilestone({ kind: 'first-external' })
      return
    }

    if (crossedThreshold(`board:${board.slug}:total-ideas:ten`, 10, stats.total_ideas)) {
      setMilestone({ kind: 'ten-ideas' })
    }
  }, [loadState, user])

  // Auto-dismiss, same rationale/duration as the referral-reward celebration.
  useEffect(() => {
    if (milestone === null) return
    const timer = setTimeout(() => setMilestone(null), 6000)
    return () => clearTimeout(timer)
  }, [milestone])

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
  const brandLogoUrl =
    loadState.phase === 'done' ? (loadState.data.board.logo_url ?? undefined) : undefined

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
      voterPreview={viewAsVoter}
      onVoterPreviewChange={setViewAsVoterPreview}
      brandLogoUrl={brandLogoUrl}
      brandLogoAlt={boardName}
      brandLogoPending={loadState.phase !== 'done'}
    />
  )

  if (loadState.phase === 'loading') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {/* Mirrors the loaded page exactly — masthead on the paper, the
            control row, then the ruled result sheet — so nothing jumps when
            the ballot lines arrive. */}
        <div
          className="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between sm:gap-8"
          aria-hidden="true"
        >
          <div className="flex flex-col gap-3 flex-1 min-w-0">
            <Skeleton className="h-3 w-16" />
            <Skeleton className="h-11 w-72 max-w-full" />
            <Skeleton className="h-5 w-96 max-w-full" />
          </div>
          <Skeleton className="h-11 w-full sm:w-40" />
        </div>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3" aria-hidden="true">
          <div className="flex flex-wrap gap-1">
            <Skeleton className="h-8 w-14" />
            <Skeleton className="h-8 w-20" />
            <Skeleton className="h-8 w-24" />
            <Skeleton className="h-8 w-24" />
          </div>
          <Skeleton className="h-8 w-48" />
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

  // Live reorder: under "Top" sort, re-rank by the current (possibly optimistic
  // or just-polled) score rather than the score at load time, so a vote can
  // promote an idea straight into the featured slot — not just to the top of
  // the plain list beneath it. Pinned ideas still rank first regardless of
  // score (mirrors the backend's own pinned-first ordering, see
  // test_pinned_idea_appears_first_regardless_of_chosen_sort). `Array.
  // prototype.sort` is stable, so ties keep their original relative order.
  // "Newest" isn't score-driven, so it's left in server order.
  const rankedIdeas: Idea[] =
    sort === 'top'
      ? [...ideas].sort((a, b) => {
          if (a.is_pinned !== b.is_pinned) return a.is_pinned ? -1 : 1
          return getVote(b).score - getVote(a).score
        })
      : ideas

  // Featured top idea: only in the canonical board-home state (page 1, default
  // sort, no status filter). Once the user sorts/filters/paginates, drop the hero
  // and show a plain list. The featured idea is excluded from the list below so it
  // is never shown twice.
  const showFeatured = page === 1 && status === null && sort === 'top' && ideas.length > 0
  const topIdea: Idea | undefined = showFeatured ? rankedIdeas[0] : undefined
  const orderedListIdeas: Idea[] = showFeatured ? rankedIdeas.slice(1) : rankedIdeas

  const submitHref = accountPath(`/${board.slug}/submit`)

  const milestoneCopy =
    milestone !== null &&
    {
      'first-idea': {
        emoji: '🌱',
        title: t('milestoneFirstIdeaTitle'),
        body: t('milestoneFirstIdeaBody'),
      },
      'first-external': {
        emoji: '🤝',
        title: t('milestoneFirstExternalTitle'),
        body: t('milestoneFirstExternalBody'),
      },
      'ten-ideas': {
        emoji: '🚀',
        title: t('milestoneTenIdeasTitle'),
        body: t('milestoneTenIdeasBody'),
      },
    }[milestone.kind]

  return (
    <div style={brandingStyle(board)}>
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {milestoneCopy && (
          <Celebration
            effect="sparkle"
            tone="info"
            emoji={milestoneCopy.emoji}
            title={milestoneCopy.title}
            className="mb-6"
          >
            {milestoneCopy.body}
          </Celebration>
        )}
        {/* Masthead — set directly on the paper ground like the landing's hero:
          eyebrow, the board name at display weight, the intro as a lead, and
          the one ink CTA. No card around it; the sheet below is the object. */}
        <header className="mb-8 md:mb-10 animate-vp-rise">
          <div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between sm:gap-8">
            <div className="min-w-0 flex-1">
              <div className="vp-eyebrow mb-3">{tCommon('header.board')}</div>
              {board.primary_color && (
                <div aria-hidden="true" className="mb-3 h-1.5 w-12 rounded-vp-full bg-vp-accent" />
              )}
              <h1 className="font-archivo font-extrabold text-vp-4xl md:text-[2.75rem] leading-[1.02] tracking-[-0.03em] text-vp-ink text-balance">
                {board.name}
              </h1>
              {/* The intro is tenant-authored plaintext — rendered through .vp-prose
                (pre-wrap, break-anywhere), never as markup. */}
              {board.intro && (
                <p className="vp-prose mt-4 max-w-[60ch] text-vp-lg md:text-vp-xl leading-7 md:leading-8 text-vp-text-secondary text-pretty">
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
        </header>

        {/* Board top — status chips left, sort right — sits directly on the
          sheet's head rule, the way the landing's ExampleBoard lays it out. */}
        <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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
            tone="accent"
          />
        </div>

        {/* The result sheet — ONE ruled plane, the same `vp-sheet vp-sheet--ruled`
          the landing's ExampleBoard draws: the declared result at its head,
          then one ballot line per idea, then the page foot. Re-keyed per query
          so a sort, filter or page change re-plays the arrival fade instead of
          swapping rows in place. */}
        <div
          key={`${sort}-${status ?? 'all'}-${page}`}
          className="vp-sheet vp-sheet--ruled animate-vp-fade-in"
        >
          {topIdea && (
            <div
              data-testid="featured-idea"
              className={cx(orderedListIdeas.length > 0 && 'border-b border-vp-rule')}
            >
              <VotableFeatured
                idea={topIdea}
                boardSlug={board.slug}
                stats={stats}
                vote={getVote(topIdea)}
                onVoteUp={() => castVote(topIdea, 'up')}
                onVoteDown={() => castVote(topIdea, 'down')}
              />
            </div>
          )}

          {ideas.length === 0 ? (
            status !== null ? (
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
            )
          ) : (
            orderedListIdeas.length > 0 && (
              <div className="divide-y divide-vp-rule" role="list" aria-label={t('ideasAriaLabel')}>
                {orderedListIdeas.map((idea) => (
                  <div key={idea.id} role="listitem" ref={registerRow(idea.id)}>
                    <VotableRow
                      idea={idea}
                      boardSlug={board.slug}
                      vote={getVote(idea)}
                      onVoteUp={() => castVote(idea, 'up')}
                      onVoteDown={() => castVote(idea, 'down')}
                    />
                  </div>
                ))}
              </div>
            )
          )}

          {total_pages > 1 && (
            <div className="border-t border-vp-rule px-4 sm:px-5 py-3 bg-vp-surface-frost">
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

        {/* "Powered by Votepit" (branding tiers): server has already re-checked
          the account's CURRENT plan before setting show_badge, so this only
          honours the flag. A quiet underlined link in the landing's link
          grammar — not a pill. */}
        {board.show_badge && (
          <p className="mt-10 text-center text-vp-xs">
            <a
              href="https://votepit.com"
              target="_blank"
              rel="noopener noreferrer"
              className="text-vp-text-muted font-medium underline decoration-2 underline-offset-[0.2em] decoration-vp-underline transition-colors hover:text-vp-ink hover:decoration-current"
            >
              {t('poweredBy')}
            </a>
          </p>
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
    </div>
  )
}

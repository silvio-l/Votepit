import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import type { SortValue } from '../components'
import {
  EmptyState,
  FeaturedIdeaCard,
  Header,
  IdeaListRow,
  PageShell,
  Pagination,
  SortTabs,
  StatusFilter,
} from '../components'
import type { Status } from '../components/StatusBadge'
import { useVote } from '../hooks/useVote'
import type { ApiError, BoardResponse, Idea } from '../lib/api'
import { bootstrap, getBoard, logout } from '../lib/api'

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
function formatTimeAgo(iso: string): string {
  const created = new Date(iso.replace(' ', 'T'))
  const diffMs = Date.now() - created.getTime()
  const mins = Math.floor(diffMs / 60_000)
  if (mins < 2) return 'gerade eben'
  if (mins < 60) return `vor ${mins} Min.`
  const hours = Math.floor(mins / 60)
  if (hours < 24) return `vor ${hours} Std.`
  const days = Math.floor(hours / 24)
  if (days < 30) return `vor ${days} Tag${days === 1 ? '' : 'en'}`
  const months = Math.floor(days / 30)
  if (months < 12) return `vor ${months} Monat${months === 1 ? '' : 'en'}`
  return `vor ${Math.floor(months / 12)} Jahr${Math.floor(months / 12) === 1 ? '' : 'en'}`
}

/** Consensus percentage from up/down counts. */
function calcConsensus(upCount: number, downCount: number): number {
  const total = upCount + downCount
  if (total === 0) return 0
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

// ── VotableRow ────────────────────────────────────────────────────────────────

interface VotableRowProps {
  idea: Idea
  boardSlug: string
  isAuthenticated: boolean
}

function VotableRow({ idea, boardSlug, isAuthenticated }: VotableRowProps) {
  const voteResult = useVote({
    boardSlug,
    ideaId: idea.id,
    isAuthenticated,
    initialScore: idea.score_cache,
    initialMyVote: idea.my_vote,
    initialUpCount: idea.up_count,
    initialDownCount: idea.down_count,
    returnTo: `/${boardSlug}/idea/${idea.id}`,
  })

  return (
    <IdeaListRow
      id={idea.id}
      title={idea.title}
      excerpt={toExcerpt(idea.body)}
      status={toComponentStatus(idea.status)}
      score={voteResult.score}
      commentCount={idea.comment_count}
      timeAgo={formatTimeAgo(idea.created_at)}
      consensusPercent={calcConsensus(voteResult.upCount, voteResult.downCount)}
      userVote={voteResult.myVote}
      onVoteUp={voteResult.onVoteUp}
      onVoteDown={voteResult.onVoteDown}
      href={`/${boardSlug}/idea/${idea.id}`}
    />
  )
}

// ── Component ─────────────────────────────────────────────────────────────────

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; notFound: boolean; message: string }
  | { phase: 'done'; data: BoardResponse }

export default function BoardPage() {
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [sort, setSort] = useState<SortValue>('newest')
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState<Status | null>(null)

  // Fetch bootstrap once on mount to seed CSRF token + auth state.
  useEffect(() => {
    bootstrap()
      .then((b) => setIsAuthenticated(b.user !== null))
      .catch(() => {
        // Bootstrap failure is non-fatal — continue without auth context.
      })
  }, [])

  // Fetch board data whenever slug / sort / page changes.
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
        setLoadState({
          phase: 'error',
          notFound,
          message: notFound ? 'Dieses Board gibt es nicht.' : 'Daten konnten nicht geladen werden.',
        })
      })
  }, [boardSlug, sort, status, page])

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
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const handleLogout = () => {
    logout()
      .catch(() => {
        // Even if logout request fails, navigate to login page.
      })
      .finally(() => {
        setIsAuthenticated(false)
        navigate('/login')
      })
  }

  // ── Render ──────────────────────────────────────────────────────────────────

  const loginLabel = isAuthenticated ? 'Konto' : 'Anmelden'

  if (loadState.phase === 'loading') {
    return (
      <PageShell
        header={
          <Header
            logoHref="/"
            loginLabel={loginLabel}
            isAuthenticated={isAuthenticated}
            onLogoutClick={handleLogout}
          />
        }
      >
        <p
          className="text-vp-text-muted text-sm text-center py-20"
          aria-live="polite"
          aria-busy="true"
        >
          Wird geladen…
        </p>
      </PageShell>
    )
  }

  if (loadState.phase === 'error') {
    return (
      <PageShell
        header={
          <Header
            logoHref="/"
            loginLabel={loginLabel}
            isAuthenticated={isAuthenticated}
            onLogoutClick={handleLogout}
          />
        }
      >
        <EmptyState
          title={loadState.notFound ? 'Board nicht gefunden' : 'Fehler beim Laden'}
          description={loadState.message}
        />
      </PageShell>
    )
  }

  const { board, ideas, total_pages } = loadState.data

  // Top idea: first by score (sorted by backend when sort=top, or just first row).
  const topIdea: Idea | undefined = ideas[0]

  return (
    <PageShell
      header={
        <Header
          logoHref={`/${board.slug}`}
          loginLabel={loginLabel}
          isAuthenticated={isAuthenticated}
          onLogoutClick={handleLogout}
        />
      }
    >
      {/* Board header */}
      <div className="mb-6">
        <h1 className="font-archivo font-extrabold text-[28px] text-vp-ink leading-[1.15]">
          {board.name}
        </h1>
        {board.intro && (
          <p className="mt-1 text-[15px] text-vp-text-secondary leading-relaxed max-w-xl">
            {board.intro}
          </p>
        )}
      </div>

      {/* Hero: FeaturedIdeaCard for the top idea (non-empty list only) */}
      {topIdea && (
        <div className="mb-8" data-testid="featured-idea">
          <FeaturedIdeaCard
            title={topIdea.title}
            description={toExcerpt(topIdea.body, 200)}
            status={toComponentStatus(topIdea.status)}
            score={topIdea.score_cache}
            commentCount={topIdea.comment_count}
            consensusPercent={calcConsensus(topIdea.up_count, topIdea.down_count)}
            weeklyVotes={0}
            weeklyNewIdeas={0}
            avgConsensusPercent={0}
          />
        </div>
      )}

      {/* Sort + action bar */}
      <div className="flex items-center justify-between mb-4 gap-3 flex-wrap">
        <SortTabs value={sort} onChange={handleSortChange} />
        <a
          href={`/${board.slug}/submit`}
          className="px-4 py-2 bg-vp-ink text-white font-semibold text-[13px] rounded-vp-md hover:opacity-90 transition-opacity focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-vp-ink"
        >
          + Idee einreichen
        </a>
      </div>

      {/* Status filter bar */}
      <div className="mb-5">
        <StatusFilter value={status} onChange={handleStatusChange} />
      </div>

      {/* Idea list */}
      {ideas.length === 0 ? (
        <EmptyState
          title="Noch keine Ideen"
          description="Sei die Erste oder der Erste, der eine Idee einreicht — und bring das Board in Gang."
          action={
            <a
              href={`/${board.slug}/submit`}
              className="px-5 py-2.5 bg-vp-ink text-white font-semibold text-[14px] rounded-vp-md hover:opacity-90 transition-opacity focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-vp-ink"
            >
              Erste Idee einreichen
            </a>
          }
        />
      ) : (
        <div className="space-y-3" role="list" aria-label="Ideen">
          {ideas.map((idea) => (
            <div key={idea.id} role="listitem">
              <VotableRow idea={idea} boardSlug={board.slug} isAuthenticated={isAuthenticated} />
            </div>
          ))}
        </div>
      )}

      {/* Pagination */}
      {total_pages > 1 && (
        <div className="mt-8">
          <Pagination page={page} totalPages={total_pages} onChange={handlePageChange} />
        </div>
      )}
    </PageShell>
  )
}

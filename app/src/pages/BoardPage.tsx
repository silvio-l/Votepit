import type { SortValue, Status } from '@votepit/ui'
import {
  Button,
  EmptyState,
  FeaturedIdeaCard,
  Header,
  IdeaListRow,
  PageShell,
  Pagination,
  SortTabs,
  StatusFilter,
} from '@votepit/ui'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
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
  const [sort, setSort] = useState<SortValue>('top')
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
            onLoginClick={() =>
              navigate(boardSlug ? `/login?r=${encodeURIComponent(`/${boardSlug}`)}` : '/login')
            }
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
            onLoginClick={() =>
              navigate(boardSlug ? `/login?r=${encodeURIComponent(`/${boardSlug}`)}` : '/login')
            }
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

  const { board, ideas, total_pages, stats } = loadState.data

  // Featured top idea: only in the canonical board-home state (page 1, default
  // sort, no status filter). Once the user sorts/filters/paginates, drop the hero
  // and show a plain list. The featured idea is excluded from the list below so it
  // is never shown twice.
  const showFeatured = page === 1 && status === null && sort === 'top' && ideas.length > 0
  const topIdea: Idea | undefined = showFeatured ? ideas[0] : undefined
  const listIdeas: Idea[] = showFeatured ? ideas.slice(1) : ideas

  return (
    <PageShell
      header={
        <Header
          logoHref={`/${board.slug}`}
          loginLabel={loginLabel}
          isAuthenticated={isAuthenticated}
          onLoginClick={() => navigate(`/login?r=${encodeURIComponent(`/${board.slug}`)}`)}
          onLogoutClick={handleLogout}
        />
      }
    >
      {/* Hero: H1 + subtitle + primary CTA — Figma 80:33 hero block (left-aligned) */}
      <div className="mb-7">
        <h1 className="font-archivo font-bold text-[40px] text-vp-ink leading-[1.08]">
          {board.name}
        </h1>
        {board.intro && (
          <p className="mt-3 text-[15px] text-vp-text-secondary leading-relaxed max-w-2xl">
            {board.intro}
          </p>
        )}
        <Button
          variant="primary"
          className="mt-4"
          onClick={() => navigate(`/${board.slug}/submit`)}
        >
          + Neue Idee
        </Button>
      </div>

      {/* Controls: status filter chips, then sort tabs — Figma filter-section (above featured) */}
      <div className="mb-6 flex flex-col gap-3">
        <StatusFilter value={status} onChange={handleStatusChange} />
        <SortTabs value={sort} onChange={handleSortChange} />
      </div>

      {/* Featured top idea (canonical board-home view) — sits below the controls per Figma */}
      {topIdea && (
        <div className="mb-3" data-testid="featured-idea">
          <FeaturedIdeaCard
            title={topIdea.title}
            description={toExcerpt(topIdea.body, 200)}
            status={toComponentStatus(topIdea.status)}
            score={topIdea.score_cache}
            commentCount={topIdea.comment_count}
            consensusPercent={calcConsensus(topIdea.up_count, topIdea.down_count)}
            weeklyVotes={stats.weekly_votes}
            weeklyNewIdeas={stats.weekly_new_ideas}
            avgConsensusPercent={stats.avg_consensus}
          />
        </div>
      )}

      {/* Idea list */}
      {ideas.length === 0 ? (
        <EmptyState
          title="Noch keine Ideen"
          description="Sei die Erste oder der Erste, der eine Idee einreicht — und bring das Board in Gang."
          action={
            <Button variant="primary" onClick={() => navigate(`/${board.slug}/submit`)}>
              Erste Idee einreichen
            </Button>
          }
        />
      ) : (
        <div className="space-y-3" role="list" aria-label="Ideen">
          {listIdeas.map((idea) => (
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

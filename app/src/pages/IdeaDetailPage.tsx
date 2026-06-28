import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import type { Status } from '../components'
import { ConsensusBar, EmptyState, Header, PageShell, StatusBadge, VoteWidget } from '../components'
import { useVote } from '../hooks/useVote'
import type { ApiError, IdeaDetailResponse } from '../lib/api'
import { bootstrap, getIdea, logout } from '../lib/api'

function toComponentStatus(raw: string): Status {
  if (raw === 'in_progress') return 'in-progress'
  const valid: Status[] = ['open', 'planned', 'in-progress', 'done', 'declined']
  return valid.includes(raw as Status) ? (raw as Status) : 'open'
}

function calcConsensus(upCount: number, downCount: number): number {
  const total = upCount + downCount
  if (total === 0) return 0
  return Math.round((upCount / total) * 100)
}

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; notFound: boolean; message: string }
  | { phase: 'done'; data: IdeaDetailResponse }

// ── IdeaDetailContent ─────────────────────────────────────────────────────────
// Extracted so useVote can be called at the top level (no conditional hook call).

interface IdeaDetailContentProps {
  data: IdeaDetailResponse
  boardSlug: string
  onLogout: () => void
}

function IdeaDetailContent({ data, boardSlug: _boardSlug, onLogout }: IdeaDetailContentProps) {
  const { board, idea, is_authenticated } = data

  const voteResult = useVote({
    boardSlug: board.slug,
    ideaId: idea.id,
    isAuthenticated: is_authenticated,
    initialScore: idea.score_cache,
    initialMyVote: idea.my_vote,
    initialUpCount: idea.up_count,
    initialDownCount: idea.down_count,
  })

  const { score, myVote, upCount, downCount, onVoteUp, onVoteDown } = voteResult
  const consensusPercent = calcConsensus(upCount, downCount)
  const componentStatus = toComponentStatus(idea.status)

  return (
    <PageShell
      header={
        <Header
          logoHref={`/${board.slug}`}
          loginLabel={is_authenticated ? 'Konto' : 'Anmelden'}
          isAuthenticated={is_authenticated}
          onLogoutClick={onLogout}
        />
      }
    >
      <a
        href={`/${board.slug}`}
        className="inline-flex items-center gap-1 text-[13px] text-vp-text-secondary hover:text-vp-ink transition-colors mb-6"
      >
        ← {board.name}
      </a>

      <article
        className="bg-vp-surface backdrop-blur-xl rounded-vp-xl border border-vp-border-subtle p-6 md:p-8"
        aria-label={idea.title}
      >
        <div className="flex gap-5 items-start">
          <div className="shrink-0 pt-0.5">
            <VoteWidget
              score={score}
              userVote={myVote}
              onVoteUp={onVoteUp}
              onVoteDown={onVoteDown}
            />
          </div>

          <div className="flex-1 min-w-0">
            <div className="flex flex-wrap items-start gap-2 mb-3">
              <h1 className="font-archivo font-extrabold text-[22px] text-vp-ink leading-[1.2] flex-1 min-w-0">
                {idea.title}
              </h1>
              <StatusBadge status={componentStatus} />
            </div>

            <p className="text-[15px] text-vp-text-secondary leading-relaxed whitespace-pre-wrap">
              {idea.body}
            </p>

            <div className="mt-5 flex flex-wrap items-center gap-4">
              <div className="flex items-center gap-3 text-[13px]">
                <span className="inline-flex items-center gap-1 text-vp-vote-up font-mono-num font-semibold">
                  <span aria-label="Upvotes">▲</span>
                  <span>{upCount}</span>
                </span>
                <span className="inline-flex items-center gap-1 text-vp-vote-down font-mono-num font-semibold">
                  <span aria-label="Downvotes">▼</span>
                  <span>{downCount}</span>
                </span>
              </div>

              {idea.comment_count > 0 && (
                <span className="text-[13px] text-vp-text-muted">
                  {idea.comment_count} {idea.comment_count === 1 ? 'Kommentar' : 'Kommentare'}
                </span>
              )}
            </div>

            <div className="mt-4 max-w-xs">
              <ConsensusBar percent={consensusPercent} />
            </div>
          </div>
        </div>
      </article>
    </PageShell>
  )
}

// ── IdeaDetailPage ────────────────────────────────────────────────────────────

export default function IdeaDetailPage() {
  const { boardSlug, ideaId } = useParams<{ boardSlug: string; ideaId: string }>()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)

  useEffect(() => {
    bootstrap()
      .then((b) => setIsAuthenticated(b.user !== null))
      .catch(() => {})
  }, [])

  useEffect(() => {
    if (!boardSlug || !ideaId) return

    setLoadState({ phase: 'loading' })

    getIdea(boardSlug, ideaId)
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
          message: notFound
            ? 'Diese Idee wurde nicht gefunden.'
            : 'Daten konnten nicht geladen werden.',
        })
      })
  }, [boardSlug, ideaId])

  const handleLogout = () => {
    logout()
      .catch(() => {})
      .finally(() => {
        setIsAuthenticated(false)
        window.location.href = '/login'
      })
  }

  const loginLabel = isAuthenticated ? 'Konto' : 'Anmelden'

  if (loadState.phase === 'loading') {
    return (
      <PageShell
        header={
          <Header
            logoHref={boardSlug ? `/${boardSlug}` : '/'}
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
            logoHref={boardSlug ? `/${boardSlug}` : '/'}
            loginLabel={loginLabel}
            isAuthenticated={isAuthenticated}
            onLogoutClick={handleLogout}
          />
        }
      >
        {loadState.notFound ? (
          <EmptyState
            title="Idee nicht gefunden"
            description="Diese Idee existiert nicht oder gehört zu einem anderen Board."
            action={
              <a
                href={boardSlug ? `/${boardSlug}` : '/'}
                className="px-4 py-2 bg-vp-ink text-white font-semibold text-[13px] rounded-vp-md hover:opacity-90 transition-opacity focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-vp-ink"
              >
                ← Zurück zum Board
              </a>
            }
          />
        ) : (
          <EmptyState title="Fehler beim Laden" description={loadState.message} />
        )}
      </PageShell>
    )
  }

  return (
    <IdeaDetailContent data={loadState.data} boardSlug={boardSlug ?? ''} onLogout={handleLogout} />
  )
}

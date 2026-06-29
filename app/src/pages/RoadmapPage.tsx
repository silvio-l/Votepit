/**
 * RoadmapPage — board-scoped, read-only Roadmap (Sprint 10, Issue 03).
 *
 * Route: /:boardSlug/roadmap
 * Trust-Level: anon (öffentlich lesbar, kein Voting).
 *
 * Ansichten: Liste (Default) | Spalten (Toggle-Stub für Issue 04).
 * Framer Motion sanftes Einstapeln (reduced-motion-sicher).
 * Figma: Liste 134:307 · RoadmapRow 141:50.
 */

import type { Status } from '@votepit/ui'
import { EmptyState, Header, PageShell, RoadmapCard, RoadmapRow, StatusBadge } from '@votepit/ui'
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import type { ApiError, RoadmapIdea, RoadmapResponse } from '../lib/api'
import { bootstrap, getRoadmap, logout } from '../lib/api'

// ── Helpers ──────────────────────────────────────────────────────────────────

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

function toExcerpt(body: string, maxChars = 120): string {
  if (body.length <= maxChars) return body
  return `${body.slice(0, maxChars).trimEnd()}…`
}

// ── View-Toggle ───────────────────────────────────────────────────────────────

type ViewMode = 'list' | 'columns'

interface ViewToggleProps {
  value: ViewMode
  onChange: (v: ViewMode) => void
}

function ViewToggle({ value, onChange }: ViewToggleProps) {
  const reduceMotion = useReducedMotion()

  const tabs: Array<{ value: ViewMode; label: string }> = [
    { value: 'list', label: 'Liste' },
    { value: 'columns', label: 'Spalten' },
  ]

  return (
    <div
      className={[
        'inline-flex gap-[3px] p-1',
        'bg-vp-surface-frost border border-vp-border-subtle rounded-vp-md',
        'shadow-vp-soft',
      ].join(' ')}
      role="tablist"
      aria-label="Ansicht"
    >
      {tabs.map((tab) => {
        const isActive = tab.value === value
        return (
          <button
            key={tab.value}
            type="button"
            role="tab"
            aria-selected={isActive}
            onClick={() => onChange(tab.value)}
            className={[
              'relative px-[15px] py-2',
              'text-[13px] rounded-vp-sm',
              'cursor-pointer transition-colors duration-150',
              isActive
                ? 'text-vp-ink font-semibold font-inter'
                : 'text-vp-text-muted font-medium font-inter hover:text-vp-ink',
            ].join(' ')}
          >
            {isActive && (
              <motion.span
                layoutId="roadmap-view-active"
                className="absolute inset-0 bg-vp-surface border border-vp-border-subtle rounded-vp-sm"
                style={{ zIndex: -1 }}
                transition={
                  reduceMotion ? { duration: 0 } : { type: 'spring', stiffness: 400, damping: 30 }
                }
              />
            )}
            {tab.label}
          </button>
        )
      })}
    </div>
  )
}

// ── Section heading ────────────────────────────────────────────────────────────

interface SectionHeadingProps {
  status: Status
  count: number
}

function SectionHeading({ status, count }: SectionHeadingProps) {
  return (
    <div className="flex items-center gap-3 mb-4">
      <StatusBadge status={status} />
      <span className="text-[13px] text-vp-text-muted font-inter">
        {count} Idee{count !== 1 ? 'n' : ''}
      </span>
    </div>
  )
}

// ── Animated section ──────────────────────────────────────────────────────────

interface AnimatedSectionProps {
  status: Status
  ideas: RoadmapIdea[]
  boardSlug: string
  reduceMotion: boolean | null
}

function AnimatedSection({ ideas, boardSlug, reduceMotion }: AnimatedSectionProps) {
  const containerVariants = {
    hidden: { opacity: 0 },
    show: {
      opacity: 1,
      transition: reduceMotion ? {} : { staggerChildren: 0.06, delayChildren: 0.05 },
    },
  }

  const itemVariants = {
    hidden: reduceMotion ? { opacity: 1, y: 0 } : { opacity: 0, y: 10 },
    show: {
      opacity: 1,
      y: 0,
      transition: reduceMotion
        ? { duration: 0 }
        : { type: 'spring' as const, stiffness: 200, damping: 24 },
    },
  }

  return (
    <motion.div
      className="space-y-3"
      role="list"
      aria-label="Roadmap-Ideen"
      variants={containerVariants}
      initial="hidden"
      animate="show"
    >
      {ideas.map((idea) => (
        <motion.div key={idea.id} role="listitem" variants={itemVariants}>
          <RoadmapRow
            id={idea.id}
            title={idea.title}
            excerpt={toExcerpt(idea.body)}
            status={toComponentStatus(idea.status)}
            score={idea.score_cache}
            commentCount={idea.comment_count}
            consensusPercent={calcConsensus(idea.up_count, idea.down_count)}
            href={`/${boardSlug}/idea/${idea.id}`}
          />
        </motion.div>
      ))}
    </motion.div>
  )
}

// ── Sections config ───────────────────────────────────────────────────────────

const SECTIONS: Array<{ key: 'planned' | 'in_progress' | 'done'; status: Status }> = [
  { key: 'planned', status: 'planned' },
  { key: 'in_progress', status: 'in-progress' },
  { key: 'done', status: 'done' },
]

// ── Page ──────────────────────────────────────────────────────────────────────

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; notFound: boolean; message: string }
  | { phase: 'done'; data: RoadmapResponse }

export default function RoadmapPage() {
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()
  const reduceMotion = useReducedMotion()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [view, setView] = useState<ViewMode>('list')

  // Seed CSRF + auth state on mount.
  useEffect(() => {
    bootstrap()
      .then((b) => setIsAuthenticated(b.user !== null))
      .catch(() => {})
  }, [])

  // Fetch roadmap data.
  useEffect(() => {
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
          message: notFound
            ? 'Dieses Board gibt es nicht.'
            : 'Roadmap konnte nicht geladen werden.',
        })
      })
  }, [boardSlug])

  const handleLogout = () => {
    logout()
      .catch(() => {})
      .finally(() => {
        setIsAuthenticated(false)
        navigate('/login')
      })
  }

  const loginLabel = isAuthenticated ? 'Konto' : 'Anmelden'
  const basePath = boardSlug ? `/${boardSlug}` : ''

  // ── Loading ────────────────────────────────────────────────────────────────

  if (loadState.phase === 'loading') {
    return (
      <PageShell
        header={
          <Header
            logoHref={basePath || '/'}
            basePath={basePath}
            loginLabel={loginLabel}
            isAuthenticated={isAuthenticated}
            onLoginClick={() =>
              navigate(boardSlug ? `/login?r=${encodeURIComponent(basePath)}` : '/login')
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

  // ── Error ──────────────────────────────────────────────────────────────────

  if (loadState.phase === 'error') {
    return (
      <PageShell
        header={
          <Header
            logoHref={basePath || '/'}
            basePath={basePath}
            loginLabel={loginLabel}
            isAuthenticated={isAuthenticated}
            onLoginClick={() =>
              navigate(boardSlug ? `/login?r=${encodeURIComponent(basePath)}` : '/login')
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

  // ── Done ───────────────────────────────────────────────────────────────────

  const { board, groups } = loadState.data

  return (
    <PageShell
      header={
        <Header
          logoHref={`/${board.slug}`}
          basePath={`/${board.slug}`}
          loginLabel={loginLabel}
          isAuthenticated={isAuthenticated}
          onLoginClick={() => navigate(`/login?r=${encodeURIComponent(`/${board.slug}`)}`)}
          onLogoutClick={handleLogout}
        />
      }
    >
      {/* Hero */}
      <div className="mb-7">
        <h1 className="font-archivo font-bold text-[40px] text-vp-ink leading-[1.08]">Roadmap</h1>
        <p className="mt-3 text-[15px] text-vp-text-secondary leading-relaxed max-w-2xl">
          {board.name} — geplante, laufende und erledigte Features im Überblick.
        </p>
      </div>

      {/* View-Toggle */}
      <div className="mb-6">
        <ViewToggle value={view} onChange={setView} />
      </div>

      {/* Views */}
      <AnimatePresence mode="wait">
        {view === 'list' && (
          <motion.div
            key="list"
            initial={reduceMotion ? { opacity: 1 } : { opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={reduceMotion ? { opacity: 1 } : { opacity: 0 }}
            transition={{ duration: reduceMotion ? 0 : 0.15 }}
          >
            <div className="space-y-10">
              {SECTIONS.map(({ key, status }) => {
                const ideas = groups[key]
                return (
                  <section key={key} aria-label={status}>
                    <SectionHeading status={status} count={ideas.length} />
                    {ideas.length === 0 ? (
                      <EmptyState
                        title="Keine Ideen"
                        description="In dieser Kategorie gibt es noch keine Einträge."
                      />
                    ) : (
                      <AnimatedSection
                        status={status}
                        ideas={ideas}
                        boardSlug={board.slug}
                        reduceMotion={reduceMotion}
                      />
                    )}
                  </section>
                )
              })}
            </div>
          </motion.div>
        )}

        {/* Spalten-View — Figma 136:456 / RoadmapCard 133:33 */}
        {view === 'columns' && (
          <motion.div
            key="columns"
            initial={reduceMotion ? { opacity: 1 } : { opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={reduceMotion ? { opacity: 1 } : { opacity: 0 }}
            transition={{ duration: reduceMotion ? 0 : 0.15 }}
          >
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {SECTIONS.map(({ key, status }) => {
                const ideas = groups[key]
                return (
                  <div key={key}>
                    <SectionHeading status={status} count={ideas.length} />
                    {ideas.length === 0 ? (
                      <EmptyState title="Keine Ideen" description="Noch keine Einträge." />
                    ) : (
                      <div className="space-y-3">
                        {ideas.map((idea) => (
                          <RoadmapCard
                            key={idea.id}
                            id={idea.id}
                            title={idea.title}
                            score={idea.score_cache}
                            consensusPercent={calcConsensus(idea.up_count, idea.down_count)}
                            href={`/${board.slug}/idea/${idea.id}`}
                          />
                        ))}
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </PageShell>
  )
}

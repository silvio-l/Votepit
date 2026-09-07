/**
 * RoadmapPage — board-scoped, read-only Roadmap.
 *
 * Route: /:boardSlug/roadmap
 * Trust level: anon (publicly readable, no voting).
 *
 * Views: list (default) | columns.
 */

import type { Status } from '@votepit/ui'
import {
  Badge,
  Button,
  EmptyState,
  ErrorState,
  LoadingState,
  PageShell,
  RoadmapCard,
  RoadmapRow,
  Section,
  Skeleton,
  SkeletonRows,
  statusDotClass,
  Tabs,
} from '@votepit/ui'
import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { LocalizedHeader, ScopeLabel } from '../components/LocalizedHeader'
import { MarkdownLite } from '../components/MarkdownLite'
import { useVoterPreview } from '../hooks/useVoterPreview'
import { accountPath } from '../lib/accountContext'
import type { ApiError, RoadmapIdea, RoadmapResponse, User } from '../lib/api'
import { bootstrap, getRoadmap, logout } from '../lib/api'
import { brandingStyle } from '../lib/branding'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

// ── Helpers ──────────────────────────────────────────────────────────────────

function toComponentStatus(raw: string): Status {
  if (raw === 'in_progress') return 'in-progress'
  const valid: Status[] = ['open', 'planned', 'in-progress', 'done', 'declined']
  return valid.includes(raw as Status) ? (raw as Status) : 'open'
}

function calcConsensus(upCount: number, downCount: number): number | null {
  const total = upCount + downCount
  if (total === 0) return null
  return Math.round((upCount / total) * 100)
}

type ViewMode = 'list' | 'columns'

/** Translation-dictionary keys per status, used for the section labels. */
const SECTION_LABEL_KEYS: Record<Status, string> = {
  open: 'sectionOpen',
  planned: 'sectionPlanned',
  'in-progress': 'sectionInProgress',
  done: 'sectionDone',
  declined: 'sectionDeclined',
}

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
      size="compact"
      headingLevel={3}
      title={t(keys.title)}
      description={t(keys.description)}
    />
  )
}

// ── Ideas list (list view) ────────────────────────────────────────────────────

function IdeaList({ ideas, boardSlug }: { ideas: RoadmapIdea[]; boardSlug: string }) {
  const t = useT('roadmapPage')
  const tCommon = useT('common')
  return (
    <div
      className="divide-y divide-vp-rule animate-vp-fade-in"
      role="list"
      aria-label={t('ideasAriaLabel')}
    >
      {ideas.map((idea) => (
        <div key={idea.id} role="listitem">
          <RoadmapRow
            id={idea.id}
            title={idea.title}
            excerpt={<MarkdownLite text={idea.body} maxChars={120} />}
            status={toComponentStatus(idea.status)}
            statusLabel={t(SECTION_LABEL_KEYS[toComponentStatus(idea.status)])}
            score={idea.score_cache}
            commentCount={idea.comment_count}
            consensusPercent={calcConsensus(idea.up_count, idea.down_count)}
            upCount={idea.up_count}
            downCount={idea.down_count}
            href={accountPath(`/${boardSlug}/idea/${idea.id}`)}
            votesLabel={tCommon('idea.votesLabel')}
            commentLabel={tCommon('idea.commentLabel')}
            commentsLabel={tCommon('idea.commentsLabel')}
            forLabel={tCommon('vote.upLabel')}
            againstLabel={tCommon('vote.downLabel')}
            consensusLabel={tCommon('vote.consensusLabel')}
            consensusLowLabel={tCommon('vote.consensusLowLabel')}
            consensusEmptyLabel={tCommon('vote.consensusEmptyLabel')}
          />
        </div>
      ))}
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; notFound: boolean; message: string }
  | { phase: 'done'; data: RoadmapResponse }

export default function RoadmapPage() {
  const t = useT('roadmapPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [view, setView] = useState<ViewMode>('list')
  const [viewAsVoter, setViewAsVoterPreview] = useVoterPreview()

  // Seed CSRF + auth state on mount.
  useEffect(() => {
    bootstrap()
      .then((b) => {
        setIsAuthenticated(b.user !== null)
        setUser(b.user)
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
    loadState.phase === 'done' ? (loadState.data.board.logo_url ?? undefined) : undefined

  const header = (
    <LocalizedHeader
      logoHref={basePath || accountPath('/')}
      basePath={basePath}
      boardSlug={boardSlug}
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
            <Skeleton className="h-3 w-24" />
            <Skeleton className="h-11 w-56 max-w-full" />
            <Skeleton className="h-5 w-80 max-w-full" />
          </div>
          <Skeleton className="h-8 w-40" />
        </div>
        <div className="flex flex-col gap-6">
          {SECTIONS.map(({ key }, i) => (
            <div key={key} className="vp-sheet px-3 sm:px-4">
              {i === 0 ? (
                <LoadingState label={t('loading')} rows={3} />
              ) : (
                <div className="py-2">
                  <SkeletonRows rows={2} />
                </div>
              )}
            </div>
          ))}
        </div>
      </PageShell>
    )
  }

  if (loadState.phase === 'error') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <ErrorState
          // A missing board is a dead end, not a failure that interrupts.
          role={loadState.notFound ? 'status' : 'alert'}
          title={loadState.notFound ? t('notFoundTitle') : t('errorHeading')}
          description={loadState.message}
          action={
            loadState.notFound ? (
              <Button type="button" variant="secondary" onClick={() => navigate(accountPath('/'))}>
                {t('backHome')}
              </Button>
            ) : (
              <Button type="button" variant="secondary" onClick={loadRoadmap}>
                {t('retry')}
              </Button>
            )
          }
        />
      </PageShell>
    )
  }

  const { board, groups } = loadState.data

  const viewItems = [
    { value: 'list' as const, label: t('viewList') },
    { value: 'columns' as const, label: t('viewColumns') },
  ]

  return (
    <div style={brandingStyle(board)}>
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {/* Masthead — set on the paper ground exactly like the board page's, so
          the two pages read as one site. */}
        <header className="mb-8 md:mb-10 animate-vp-rise">
          <div className="flex flex-wrap items-end justify-between gap-x-8 gap-y-5">
            <div className="min-w-0 flex-1">
              <div className="vp-eyebrow mb-3">{board.name}</div>
              {board.primary_color && (
                <div aria-hidden="true" className="mb-3 h-1.5 w-12 rounded-vp-full bg-vp-accent" />
              )}
              <h1 className="font-archivo font-extrabold text-vp-4xl md:text-[2.75rem] leading-[1.02] tracking-[-0.03em] text-vp-ink text-balance">
                {t('heading')}
              </h1>
              <p className="mt-4 max-w-[60ch] text-vp-lg md:text-vp-xl leading-7 md:leading-8 text-vp-text-secondary text-pretty">
                {t('subtitle', { name: board.name })}
              </p>
            </div>
            <Tabs
              items={viewItems}
              value={view}
              onChange={setView}
              ariaLabel={t('viewAriaLabel')}
              variant="segmented"
            />
          </div>
        </header>

        {view === 'list' ? (
          <div className="flex flex-col gap-6" key="list">
            {SECTIONS.map(({ key, status }) => {
              const ideas = groups[key]
              return (
                <Section
                  key={key}
                  title={<SectionTitle status={status} label={t(SECTION_LABEL_KEYS[status])} />}
                  actions={<SectionCount count={ideas.length} />}
                  flush
                >
                  {ideas.length === 0 ? (
                    <SectionEmpty sectionKey={key} />
                  ) : (
                    <IdeaList ideas={ideas} boardSlug={board.slug} />
                  )}
                </Section>
              )
            })}
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 items-start" key="columns">
            {SECTIONS.map(({ key, status }) => {
              const ideas = groups[key]
              return (
                <Section
                  key={key}
                  title={<SectionTitle status={status} label={t(SECTION_LABEL_KEYS[status])} />}
                  actions={<SectionCount count={ideas.length} />}
                  flush
                >
                  {ideas.length === 0 ? (
                    <SectionEmpty sectionKey={key} />
                  ) : (
                    <div
                      className="divide-y divide-vp-rule animate-vp-fade-in"
                      role="list"
                      aria-label={t(SECTION_LABEL_KEYS[status])}
                    >
                      {ideas.map((idea) => (
                        <div key={idea.id} role="listitem">
                          <RoadmapCard
                            id={idea.id}
                            title={idea.title}
                            score={idea.score_cache}
                            consensusPercent={calcConsensus(idea.up_count, idea.down_count)}
                            href={accountPath(`/${board.slug}/idea/${idea.id}`)}
                            votesLabel={tCommon('idea.votesLabel')}
                            consensusLabel={tCommon('vote.consensusLabel')}
                            consensusLowLabel={tCommon('vote.consensusLowLabel')}
                            consensusEmptyLabel={tCommon('vote.consensusEmptyLabel')}
                          />
                        </div>
                      ))}
                    </div>
                  )}
                </Section>
              )
            })}
          </div>
        )}
      </PageShell>
    </div>
  )
}

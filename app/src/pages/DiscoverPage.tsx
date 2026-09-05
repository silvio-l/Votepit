/**
 * DiscoverPage — /discover
 *
 * Global (not account-/board-scoped), anon-readable listing of every board
 * whose owner set visibility to 'public' (BoardDiscoveryAction), across all
 * accounts. Not gated behind login — mirrors the trust level of a public
 * board itself.
 *
 * Self-host only in practice: on Cloud this route immediately redirects to
 * the marketing-styled equivalent that lives in web/ (see the useEffect
 * below) — self-host has no separate marketing site to redirect to, so it
 * keeps this in-app page as its one discovery UI.
 *
 * Board names/idea counts are user-submitted data from OTHER, unrelated
 * accounts — rendered as plain React text (never dangerouslySetInnerHTML),
 * same as everywhere else board names are shown.
 */

import {
  Button,
  Card,
  EmptyState,
  ErrorState,
  LoadingState,
  PageShell,
  Pagination,
} from '@votepit/ui'
import { ChevronUp, Lightbulb } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { LocalizedHeader } from '../components/LocalizedHeader'
import { accountPath } from '../lib/accountContext'
import type { PublicDiscoveryBoard, User } from '../lib/api'
import { bootstrap, fetchPublicBoardDiscovery, logout } from '../lib/api'
import { getEdition } from '../lib/edition'
import { getFeatures, legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

const LIMIT = 24

// The SPA is built once and deployed byte-identical across every Cloud
// environment (production, staging, demo, …) — see tools/deploy.sh. The
// per-environment marketing-discover redirect target therefore can't be a
// literal baked in here; it comes from the extension-supplied
// `marketing_discover_url` bootstrap feature (see features.ts), which each
// environment's Cloud extension config sets to its own marketing site.
function marketingDiscoverUrl(): string {
  return getFeatures().marketing_discover_url ?? 'https://votepit.com/discover'
}

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; message: string }
  | { phase: 'done'; boards: PublicDiscoveryBoard[]; total: number }

export default function DiscoverPage() {
  const t = useT('discoverPage')
  const { language } = useI18n()
  const navigate = useNavigate()

  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [page, setPage] = useState(1)
  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })

  // Cloud has a purpose-built, marketing-styled discovery page on the
  // landing site (fetching the same data cross-origin — see
  // web/src/pages/discover.astro). This in-app route only serves self-host,
  // where there is no separate marketing site to redirect to.
  useEffect(() => {
    if (getEdition() === 'cloud') window.location.replace(marketingDiscoverUrl())
  }, [])

  useEffect(() => {
    bootstrap()
      .then((b) => {
        setIsAuthenticated(b.user !== null)
        setUser(b.user)
      })
      .catch(() => {})
  }, [])

  const load = useCallback((p: number) => {
    setLoadState({ phase: 'loading' })
    fetchPublicBoardDiscovery(p, LIMIT)
      .then((data) => setLoadState({ phase: 'done', boards: data.boards, total: data.total }))
      .catch(() => setLoadState({ phase: 'error', message: '' }))
  }, [])

  useEffect(() => {
    load(page)
  }, [load, page])

  const handleLogout = () => {
    logout()
      .catch(() => {})
      .finally(() => {
        setIsAuthenticated(false)
        setUser(null)
        navigate('/login')
      })
  }

  const header = (
    <LocalizedHeader
      logoHref={accountPath('/')}
      isAuthenticated={isAuthenticated}
      user={user}
      onLoginClick={() => navigate('/login')}
      onLogoutClick={handleLogout}
    />
  )

  if (getEdition() === 'cloud' || loadState.phase === 'loading') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <LoadingState label={t('loading')} rows={6} />
      </PageShell>
    )
  }

  if (loadState.phase === 'error') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <ErrorState
          title={t('errorHeading')}
          description={t('errorLoadFailed')}
          action={
            <Button type="button" variant="secondary" onClick={() => load(page)}>
              {t('retry')}
            </Button>
          }
        />
      </PageShell>
    )
  }

  const { boards, total } = loadState
  const totalPages = Math.max(1, Math.ceil(total / LIMIT))

  return (
    <PageShell header={header} legalLinks={legalLinksFor(language)}>
      <header className="mb-8 md:mb-10 animate-vp-rise">
        <h1 className="font-archivo font-extrabold text-vp-4xl md:text-[2.75rem] leading-[1.02] tracking-[-0.03em] text-vp-ink text-balance">
          {t('heading')}
        </h1>
        <p className="mt-4 max-w-[60ch] text-vp-lg md:text-vp-xl leading-7 md:leading-8 text-vp-text-secondary text-pretty">
          {t('subtitle')}
        </p>
      </header>

      {boards.length === 0 ? (
        <EmptyState title={t('emptyTitle')} description={t('emptyDescription')} />
      ) : (
        <>
          <ul
            className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 list-none m-0 p-0 vp-stagger"
            aria-label={t('boardsAriaLabel')}
          >
            {boards.map((board) => (
              <li key={`${board.account_slug}/${board.slug}`} className="animate-vp-rise">
                <Link
                  to={`/${board.account_slug}/${board.slug}`}
                  className="block no-underline h-full"
                >
                  <Card interactive className="h-full flex flex-col gap-2">
                    <span className="font-semibold text-vp-md text-vp-ink leading-6 truncate">
                      {board.name}
                    </span>
                    <span className="flex items-center gap-3 text-vp-xs text-vp-text-secondary">
                      <span className="inline-flex items-center gap-1.5">
                        <ChevronUp size={13} aria-hidden="true" />
                        <span className="font-mono-num text-vp-ink">{board.vote_count}</span>
                        <span className="sr-only">
                          {t('votesCount', { count: board.vote_count })}
                        </span>
                      </span>
                      <span className="inline-flex items-center gap-1.5">
                        <Lightbulb size={13} aria-hidden="true" />
                        <span className="font-mono-num text-vp-ink">{board.idea_count}</span>
                        <span className="sr-only">
                          {t('ideasCount', { count: board.idea_count })}
                        </span>
                      </span>
                    </span>
                  </Card>
                </Link>
              </li>
            ))}
          </ul>

          {totalPages > 1 && (
            <div className="mt-8 flex justify-center">
              <Pagination
                page={page}
                totalPages={totalPages}
                onChange={setPage}
                prevLabel={t('paginationPrev')}
                nextLabel={t('paginationNext')}
                prevAriaLabel={t('paginationPrev')}
                nextAriaLabel={t('paginationNext')}
                pageOfLabel={(p, tp) => t('paginationPageOf', { page: p, totalPages: tp })}
              />
            </div>
          )}
        </>
      )}
    </PageShell>
  )
}

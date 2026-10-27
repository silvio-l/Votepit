import {
  boardInitialsName,
  boardLogoSrc,
  boardWordmarkPlated,
  boardWordmarkSrc,
} from '../lib/boardLogo'

/**
 * ImpressumPage — /:boardSlug/impressum
 *
 * Dedicated, low-key public legal-notice page for a board's operator
 * identity (international-Impressum research, 2026-09-09 — comparable
 * platforms like Shopify publish this as its own linked page rather than
 * inlining it in the footer, see BoardOperatorFooter's class doc). Renders
 * the account's `legal_operator_name`/`legal_operator_address`/
 * `legal_operator_email`/`legal_operator_notice` (BoardHomeAction), plus a
 * short DSGVO note clarifying the account owner — not Votepit — is the
 * GDPR "Verantwortlicher" for this board, linking to Votepit's own privacy
 * policy for how Votepit itself processes data as the platform/processor.
 * Deliberately does NOT attempt to draft a per-operator privacy policy —
 * out of scope, and not legal advice.
 *
 * Trust level: anon (publicly readable, same as BoardPage/RoadmapPage).
 * Reuses getBoard() (same data BoardPage already fetches) rather than a
 * dedicated endpoint — the legal_operator_* fields are already part of
 * every board-read response.
 */

import { Button, InlineNotice, PageShell, Section, Skeleton } from '@votepit/ui/ds'
import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { BoardBrandFooter } from '../components/BoardBrandFooter'
import { BoardThemeMode } from '../components/BoardThemeMode'
import { LocalizedHeader, ScopeLabel } from '../components/LocalizedHeader'
import { NotFoundState } from '../components/NotFoundState'
import { accountPath } from '../lib/accountContext'
import type { ApiError, BoardResponse, User } from '../lib/api'
import { bootstrap, getBoard, logout } from '../lib/api'
import { brandingStyle } from '../lib/branding'
import { legalLinksFor } from '../lib/features'
import type { Language } from '../lib/i18n/context'
import { useI18n, useT } from '../lib/i18n/context'
import { useResolvedTheme } from '../lib/theme'

/** The marketing site's privacy policy exists in de/en; other UI languages get the English one. */
function privacyUrl(language: Language): string {
  return language === 'de' ? 'https://votepit.com/de/datenschutz' : 'https://votepit.com/privacy'
}

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; notFound: boolean; message: string }
  | { phase: 'done'; board: BoardResponse['board'] }

export default function ImpressumPage() {
  const resolvedTheme = useResolvedTheme()
  const t = useT('impressumPage')
  const { language } = useI18n()
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  useEffect(() => {
    bootstrap()
      .then((b) => {
        setIsAuthenticated(b.user !== null)
        setUser(b.user)
      })
      .catch(() => {})
  }, [])

  const load = useCallback(() => {
    if (!boardSlug) return
    setLoadState({ phase: 'loading' })
    getBoard(boardSlug)
      .then((data) => setLoadState({ phase: 'done', board: data.board }))
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const notFound = apiErr.name === 'ApiError' && apiErr.status === 404
        setLoadState({ phase: 'error', notFound, message: t('errorLoadFailed') })
      })
  }, [boardSlug, t])

  useEffect(() => {
    load()
  }, [load])

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
  const boardName = loadState.phase === 'done' ? loadState.board.name : undefined
  const look = loadState.phase === 'done' ? loadState.board : null

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
      brandLogoUrl={boardLogoSrc(boardSlug, look)}
      brandWordmarkUrl={boardWordmarkSrc(boardSlug, look)}
      brandWordmarkPlated={boardWordmarkPlated(look)}
      brandInitialsName={boardInitialsName(look)}
      brandAccent={look?.primary_color ?? undefined}
      brandLogoAlt={boardName}
      boardHeaderLinks={look?.branding_pro?.header_links}
      hideRoadmap={look?.branding_pro?.roadmap_hidden}
    />
  )

  if (loadState.phase === 'loading') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <Skeleton lines={4} label={t('loading')} testId="impressum-loading" />
      </PageShell>
    )
  }

  // A missing or non-public board shows the shared not-found page, with no identifiers on it.
  if (loadState.phase === 'error' && loadState.notFound) {
    return <NotFoundState title={t('notFoundTitle')} message={t('notFoundDescription')} />
  }

  if (loadState.phase === 'error') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <InlineNotice
          tone="error"
          title={t('errorHeading')}
          testId="impressum-error"
          action={
            <Button testId="impressum-retry" variant="secondary" onClick={load}>
              {t('retry')}
            </Button>
          }
        >
          {loadState.message}
        </InlineNotice>
      </PageShell>
    )
  }

  const { board } = loadState
  const hasIdentity = board.legal_operator_name !== null

  return (
    <div style={brandingStyle(board, resolvedTheme)}>
      <BoardThemeMode mode={board.theme_mode} boardSlug={board.slug} />
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <header className="mb-8 animate-vp-rise">
          <div className="vp-eyebrow mb-3">{board.name}</div>
          <h1 className="font-archivo font-extrabold text-vp-4xl leading-vp-display tracking-vp-display text-vp-title text-balance">
            {t('heading')}
          </h1>
          <p className="mt-4 max-w-vp-measure text-vp-lg leading-7 text-vp-text-secondary text-pretty">
            {t('subtitle', { name: board.name })}
          </p>
        </header>

        {!hasIdentity ? (
          <Section testId="impressum-section-flush" flush>
            <InlineNotice tone="info" title={t('missingTitle')} testId="impressum-missing">
              {t('missingBody')}
            </InlineNotice>
          </Section>
        ) : (
          <div className="flex flex-col gap-6">
            <Section testId="impressum-section-plain">
              <dl className="flex flex-col gap-4 text-vp-sm text-vp-title">
                <div>
                  <dt className="font-semibold text-vp-text-muted">{t('nameLabel')}</dt>
                  <dd className="mt-1">{board.legal_operator_name}</dd>
                </div>
                {board.legal_operator_address !== null && (
                  <div>
                    <dt className="font-semibold text-vp-text-muted">{t('addressLabel')}</dt>
                    <dd className="mt-1 whitespace-pre-line">{board.legal_operator_address}</dd>
                  </div>
                )}
                {board.legal_operator_email !== null && (
                  <div>
                    <dt className="font-semibold text-vp-text-muted">{t('emailLabel')}</dt>
                    <dd className="mt-1">
                      <a
                        data-testid="impressum-operator-email"
                        href={`mailto:${board.legal_operator_email}`}
                        className="underline decoration-vp-underline hover:text-vp-accent"
                      >
                        {board.legal_operator_email}
                      </a>
                    </dd>
                  </div>
                )}
                {board.legal_operator_notice !== null && (
                  <div>
                    <dt className="font-semibold text-vp-text-muted">{t('noticeHeading')}</dt>
                    <dd className="mt-1 whitespace-pre-line">{board.legal_operator_notice}</dd>
                  </div>
                )}
              </dl>
            </Section>

            <Section testId="impressum-privacy-section" title={t('privacyHeading')}>
              <p className="text-vp-sm leading-6 text-vp-text-secondary">
                {t('privacyBody')}{' '}
                <a
                  data-testid="impressum-privacy-link"
                  href={privacyUrl(language)}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline decoration-vp-underline hover:text-vp-accent"
                >
                  {t('privacyLinkLabel')}
                </a>
                .
              </p>
            </Section>
          </div>
        )}
        <BoardBrandFooter slug={board.slug} legalOperatorName={null} visual={board} />
      </PageShell>
    </div>
  )
}

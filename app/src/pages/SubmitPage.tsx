import {
  boardInitialsName,
  boardLogoSrc,
  boardWordmarkPlated,
  boardWordmarkSrc,
} from '../lib/boardLogo'

/**
 * SubmitPage — /{boardSlug}/submit
 *
 * Renders the "submit a new idea" form for authenticated users.
 * Anon users are redirected to /login?r=… (return-to pattern, #10) — unless
 * the board accepts ideas without an account (ADR 0006): then they get the
 * guest variant (inline Turnstile, no links, a thanks state instead of the
 * idea page, and the "my ideas on this device" panel).
 *
 * Anti-spam:
 *   - honeypot field `website` (always '' — server rejects non-empty)
 *   - time-trap field `_form_at` (server-signed stamp from GET /ideas/new)
 *
 * Error mapping: 422 `error.fields` → inline per-field messages via
 * TextField/TextArea `error` prop.
 */

import {
  BackLink,
  ButtonLink,
  ErrorState,
  LoadingState,
  PageHeader,
  PageShell,
} from '@votepit/ui/ds'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { BoardBrandFooter } from '../components/BoardBrandFooter'
import { BoardThemeMode } from '../components/BoardThemeMode'
import { IdeaSubmitForm } from '../components/IdeaSubmitForm'
import { LocalizedHeader, ScopeLabel } from '../components/LocalizedHeader'
import { RouterButtonLink } from '../components/RouterButtonLink'
import { useVoterPreview } from '../hooks/useVoterPreview'
import { accountPath } from '../lib/accountContext'
import type {
  ApiError,
  BoardPhasesInfo,
  BoardVisual,
  Tag,
  TagSuggestionAvailability,
  User,
} from '../lib/api'
import { bootstrap, getSubmitForm, logout } from '../lib/api'
import { type BrandingPro, brandingProAttributes, brandingStyle } from '../lib/branding'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'
import { useResolvedTheme } from '../lib/theme'

type LoadPhase =
  | { tag: 'loading' }
  | { tag: 'error'; message: string }
  | {
      tag: 'ready'
      boardName: string
      phases: BoardPhasesInfo | undefined
      /** The board's existing tags for the optional picker (P-34). */
      tags: Tag[]
      /** AI tag suggestion availability (P-35). */
      tagSuggestion?: TagSuggestionAvailability
      /** Guest site key when submitting without an account; null for members. */
      guestSiteKey: string | null
      /** Pro branding (presets, wording) of the board; null = Votepit defaults. */
      brandingPro: BrandingPro | null
      /** The board's look (colours, logo, header links) the page applies. */
      look: BoardVisual
    }

export default function SubmitPage() {
  const resolvedTheme = useResolvedTheme()
  const t = useT('submitPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()

  const [loadPhase, setLoadPhase] = useState<LoadPhase>({ tag: 'loading' })
  const [user, setUser] = useState<User | null>(null)
  const [viewAsVoter, setViewAsVoterPreview] = useVoterPreview()

  // Time-trap stamp from server — kept in a ref, not state (no re-render needed).
  const formAtRef = useRef<string>('')

  useEffect(() => {
    if (!boardSlug) return
    const slug: string = boardSlug
    let cancelled = false

    async function init() {
      try {
        const [boot, formData] = await Promise.all([bootstrap(), getSubmitForm(slug)])
        if (cancelled) return

        const guestSiteKey =
          !formData.is_authenticated &&
          formData.board.anonymous_submission_available === true &&
          typeof formData.board.turnstile_site_key === 'string' &&
          formData.board.turnstile_site_key !== ''
            ? formData.board.turnstile_site_key
            : null

        if (!formData.is_authenticated && guestSiteKey === null) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/${slug}/submit`))}`, {
            replace: true,
          })
          return
        }

        formAtRef.current = formData.form_at
        setUser(boot.user)
        setLoadPhase({
          tag: 'ready',
          boardName: formData.board.name,
          phases: formData.board.phases,
          tags: formData.board.tags ?? [],
          tagSuggestion: formData.board.tag_suggestion?.availability,
          guestSiteKey,
          brandingPro: formData.board.branding_pro ?? null,
          look: formData.board,
        })
      } catch (err) {
        if (cancelled) return
        const msg =
          (err as ApiError)?.payload?.message ?? (err as ApiError)?.message ?? t('loadError')
        setLoadPhase({ tag: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [boardSlug, navigate, t])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const boardHref = accountPath(`/${boardSlug ?? ''}`)
  const boardName = loadPhase.tag === 'ready' ? loadPhase.boardName : undefined
  const look = loadPhase.tag === 'ready' ? loadPhase.look : null

  const header = (
    <LocalizedHeader
      logoHref={boardHref}
      basePath={boardHref}
      boardSlug={boardSlug}
      isAuthenticated={user !== null}
      user={user}
      onLogoutClick={handleLogout}
      onLoginClick={() => navigate(`/login?r=${encodeURIComponent(boardHref)}`)}
      scope={<ScopeLabel section={boardName} />}
      voterPreview={viewAsVoter}
      onVoterPreviewChange={setViewAsVoterPreview}
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

  if (loadPhase.tag === 'loading') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <LoadingState label={t('loading')} rows={4} />
      </PageShell>
    )
  }

  if (loadPhase.tag === 'error') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <ErrorState
          title={tCommon('state.errorTitle')}
          description={loadPhase.message}
          action={
            <ButtonLink
              href={boardHref}
              linkAs={RouterButtonLink}
              variant="secondary"
              testId="submit-back-to-board"
            >
              <ArrowLeft size={16} strokeWidth={2} aria-hidden="true" />
              {t('backToList')}
            </ButtonLink>
          }
        />
      </PageShell>
    )
  }

  return (
    <div
      style={look ? brandingStyle(look, resolvedTheme) : undefined}
      {...brandingProAttributes(loadPhase.tag === 'ready' ? loadPhase.brandingPro : null)}
    >
      {look && boardSlug ? <BoardThemeMode mode={look.theme_mode} boardSlug={boardSlug} /> : null}
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <PageHeader
          title={t('heading')}
          description={t('subtitle')}
          back={
            <BackLink as={Link} to={boardHref} testId="submit-back-link">
              {t('backToList')}
            </BackLink>
          }
        />

        <IdeaSubmitForm
          brandingPro={loadPhase.tag === 'ready' ? loadPhase.brandingPro : null}
          boardSlug={boardSlug ?? ''}
          boardHref={boardHref}
          phases={loadPhase.phases}
          tags={loadPhase.tags}
          tagSuggestion={loadPhase.tagSuggestion}
          guestSiteKey={loadPhase.guestSiteKey}
          user={user}
          formAt={formAtRef.current}
          onCreated={(ideaId) => navigate(accountPath(`/${boardSlug}/idea/${ideaId}`))}
        />
        {look && <BoardBrandFooter slug={boardSlug ?? ''} legalOperatorName={null} visual={look} />}
      </PageShell>
    </div>
  )
}

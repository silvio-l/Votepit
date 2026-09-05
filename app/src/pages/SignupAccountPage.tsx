/**
 * SignupAccountPage — /signup/account
 *
 * Cloud onboarding, step 2 (cloud signup onboarding): account-name/
 * slug + first-board-name/slug picker. Reached via GET /login/verify's
 * `redirect` (SignupPage requests the magic link with `r=/signup/account`) —
 * by the time this page loads, the owner's email is already verified and a
 * session cookie already exists.
 *
 * Auth gate:
 *   - Anon (no session)                → redirect to /login?r=/signup/account
 *   - Already belongs to an account    → informational message, no form
 *     (one account per signup — ADR 0001 §2c decision 17; GET /signup/account
 *     reports has_account so this never needs a failed POST to discover it)
 *   - Fresh user, no account yet       → the account+board picker form
 *
 * On success this account/board pair is IMMEDIATELY public (confirm-before-
 * public is satisfied structurally — reaching this page already required a
 * verified magic-link click, see SignupAccountAction). The success state
 * links straight into the new board at `/{accountSlug}/{boardSlug}` — built
 * directly from the createSignupAccount() response, NOT via accountPath():
 * this page renders under GlobalLayout (no :accountSlug param yet), and the
 * account context only becomes "current" once ScopedLayout picks it up from
 * the URL after navigation.
 *
 * Referral link capture (social-features ticket 01): right after
 * createSignupAccount() succeeds, consumeReferralRef() reads back the ref
 * SignupPage stashed (if any) and — best-effort, non-blocking — calls
 * POST /referrals/capture with it. A failure here must never prevent the
 * user from reaching their new board, so it is deliberately swallowed.
 */

import {
  Alert,
  Button,
  buttonClassName,
  EmptyState,
  ErrorState,
  Fieldset,
  LoadingState,
  PageHeader,
  PageShell,
  Section,
  Steps,
  TextInput,
} from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import { LocalizedHeader } from '../components/LocalizedHeader'
import type { ApiError } from '../lib/api'
import {
  bootstrap,
  captureReferral,
  createSignupAccount,
  getSignupStatus,
  logout,
} from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'
import { consumeReferralRef } from '../lib/referral'

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Client-side slug suggestion — purely cosmetic, not authoritative (the
 * server validates via SlugValidator). Identical to BoardsAdminPage's
 * slugify(), duplicated here instead of shared (two small, independent pages).
 */
function slugify(input: string): string {
  return input
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '') // strip combining diacritics (ä → a + ◌̈) — export-ok: comment-language
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64)
}

// ── Types ─────────────────────────────────────────────────────────────────────

type PageState =
  | { phase: 'loading' }
  | { phase: 'already_has_account'; accountSlug: string | null }
  | { phase: 'error'; message: string }
  | { phase: 'form' }
  | { phase: 'done'; accountSlug: string; boardSlug: string }

// ── Component ─────────────────────────────────────────────────────────────────

export default function SignupAccountPage() {
  const navigate = useNavigate()
  const t = useT('signupAccountPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })

  const [accountName, setAccountName] = useState('')
  const [accountSlug, setAccountSlug] = useState('')
  const [accountSlugEdited, setAccountSlugEdited] = useState(false)
  const [boardName, setBoardName] = useState('')
  const [boardSlug, setBoardSlug] = useState('')
  const [boardSlugEdited, setBoardSlugEdited] = useState(false)

  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [isAuthenticated, setIsAuthenticated] = useState(false)

  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent('/signup/account')}`, { replace: true })
          return
        }
        setIsAuthenticated(true)

        const status = await getSignupStatus()
        if (cancelled) return

        setPageState(
          status.has_account
            ? {
                phase: 'already_has_account',
                accountSlug: boot.user.memberships[0]?.account_slug ?? null,
              }
            : { phase: 'form' },
        )
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent('/signup/account')}`, { replace: true })
          return
        }
        const msg =
          (apiErr as ApiError)?.payload?.message ?? (err as Error)?.message ?? t('loadError')
        setPageState({ phase: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate, t])

  const handleAccountNameChange = (value: string) => {
    setAccountName(value)
    if (!accountSlugEdited) setAccountSlug(slugify(value))
  }

  const handleAccountSlugChange = (value: string) => {
    setAccountSlugEdited(true)
    setAccountSlug(value)
  }

  const handleBoardNameChange = (value: string) => {
    setBoardName(value)
    if (!boardSlugEdited) setBoardSlug(slugify(value))
  }

  const handleBoardSlugChange = (value: string) => {
    setBoardSlugEdited(true)
    setBoardSlug(value)
  }

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  // No account context exists yet on this page (that's the whole point of
  // it) — navLinks=[] suppresses Header's default Board/Roadmap links,
  // which would otherwise resolve to dead URLs here (no board/account).
  const header = (
    <LocalizedHeader
      navLinks={[]}
      isAuthenticated={isAuthenticated}
      onLogoutClick={handleLogout}
      onLoginClick={() => navigate('/login')}
    />
  )

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (creating) return

    setCreating(true)
    setFieldErrors({})
    setGeneralError(null)

    try {
      const result = await createSignupAccount({
        account_name: accountName.trim(),
        account_slug: accountSlug.trim(),
        board_name: boardName.trim(),
        board_slug: boardSlug.trim(),
      })

      const referrerSlug = consumeReferralRef()
      if (referrerSlug !== null) {
        // Best-effort — never block reaching the new board on this.
        captureReferral(referrerSlug).catch(() => {})
      }

      setPageState({
        phase: 'done',
        accountSlug: result.account_slug,
        boardSlug: result.board_slug,
      })
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        setGeneralError(apiErr?.payload?.message ?? tCommon('state.error'))
      }
      setCreating(false)
    }
  }

  if (pageState.phase === 'loading') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <LoadingState label={t('loading')} rows={4} />
      </PageShell>
    )
  }

  if (pageState.phase === 'error') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />
      </PageShell>
    )
  }

  if (pageState.phase === 'already_has_account') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <div className="vp-sheet vp-sheet--ruled">
          <EmptyState
            title={t('alreadyHasAccountTitle')}
            description={t('alreadyHasAccountBody')}
            action={
              pageState.accountSlug !== null ? (
                <Link
                  to={`/${pageState.accountSlug}/admin/boards`}
                  className={buttonClassName('primary', 'md')}
                >
                  {t('manageAccountCta')}
                </Link>
              ) : undefined
            }
          />
        </div>
      </PageShell>
    )
  }

  if (pageState.phase === 'done') {
    const boardPath = `/${pageState.accountSlug}/${pageState.boardSlug}`
    return (
      <AuthShell>
        <AuthOutcome
          tone="success"
          title={t('doneTitle')}
          headingLevel="h1"
          role="status"
          action={
            <Link
              to={`/${pageState.accountSlug}/admin/boards`}
              className={buttonClassName('primary', 'lg', 'w-full')}
            >
              {t('manageAccountCta')}
            </Link>
          }
        >
          <p>
            {t('doneBodyBeforePath')}{' '}
            <Link to={boardPath} className="font-mono-num font-medium text-vp-ink underline">
              {boardPath}
            </Link>{' '}
            {t('doneBodyAfterPath')}
          </p>
        </AuthOutcome>
      </AuthShell>
    )
  }

  const stepItems = [
    { label: t('stepEmail') },
    { label: t('stepAccount') },
    { label: t('stepReady') },
  ]

  return (
    <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
      <PageHeader title={t('heading')} description={t('subheading')} size="display" />

      <Section
        emphasis="ruled"
        className="animate-vp-rise"
        footer={
          <Button
            type="submit"
            form="signup-account-form"
            variant="primary"
            size="lg"
            disabled={creating}
            loading={creating}
            aria-busy={creating}
          >
            {creating ? t('submitSubmitting') : t('submit')}
          </Button>
        }
      >
        <Steps items={stepItems} current={1} ariaLabel={t('stepsAriaLabel')} className="mb-6" />

        <form
          id="signup-account-form"
          onSubmit={handleSubmit}
          noValidate
          className="flex flex-col gap-6"
        >
          <Fieldset legend={t('accountLegend')}>
            <TextInput
              label={t('nameLabel')}
              name="account_name"
              id="signup-account-name"
              value={accountName}
              onChange={handleAccountNameChange}
              placeholder={t('accountNamePlaceholder')}
              error={fieldErrors.account_name}
              required
            />

            <TextInput
              label={t('slugLabel')}
              name="account_slug"
              id="signup-account-slug"
              value={accountSlug}
              onChange={handleAccountSlugChange}
              placeholder={t('accountSlugPlaceholder')}
              hint={
                fieldErrors.account_slug === undefined
                  ? t('accountSlugHint', { host: window.location.host })
                  : undefined
              }
              error={fieldErrors.account_slug}
              required
              disabled={creating}
              mono
            />
          </Fieldset>

          <hr className="border-vp-border-subtle" />

          <Fieldset legend={t('firstBoardLegend')}>
            <TextInput
              label={t('nameLabel')}
              name="board_name"
              id="signup-board-name"
              value={boardName}
              onChange={handleBoardNameChange}
              placeholder={t('boardNamePlaceholder')}
              error={fieldErrors.board_name}
              required
            />

            <TextInput
              label={t('slugLabel')}
              name="board_slug"
              id="signup-board-slug"
              value={boardSlug}
              onChange={handleBoardSlugChange}
              placeholder={t('boardSlugPlaceholder')}
              error={fieldErrors.board_slug}
              required
              disabled={creating}
              mono
            />
          </Fieldset>

          {generalError !== null && <Alert tone="error">{generalError}</Alert>}
        </form>
      </Section>
    </PageShell>
  )
}

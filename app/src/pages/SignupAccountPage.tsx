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
 */

import {
  Alert,
  Button,
  buttonClassName,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  PageShell,
  TextInput,
} from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { LocalizedHeader } from '../components/LocalizedHeader'
import type { ApiError } from '../lib/api'
import { bootstrap, createSignupAccount, getSignupStatus, logout } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

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
        <div className="vp-card vp-sheet--ruled">
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
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <div
          role="status"
          className="vp-card vp-sheet--ruled flex flex-col items-center text-center gap-3 px-6 py-14 animate-vp-rise"
        >
          <span
            aria-hidden="true"
            className="flex items-center justify-center size-12 rounded-vp-full bg-vp-vote-up-soft text-vp-vote-up-strong animate-vp-stamp"
          >
            <svg
              aria-hidden="true"
              viewBox="0 0 16 16"
              width="16"
              height="16"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.75"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M3.5 8.5l3 3 6-7" />
            </svg>
          </span>
          <h1 className="font-archivo font-bold text-vp-2xl tracking-[-0.02em] text-vp-ink">
            {t('doneTitle')}
          </h1>
          <p className="text-vp-base text-vp-text-secondary max-w-md">
            {t('doneBodyBeforePath')}{' '}
            <Link to={boardPath} className="font-mono-num font-medium text-vp-ink underline">
              {boardPath}
            </Link>{' '}
            {t('doneBodyAfterPath')}
          </p>
          <div className="mt-2">
            <Link
              to={`/${pageState.accountSlug}/admin/boards`}
              className={buttonClassName('primary', 'md')}
            >
              {t('manageAccountCta')}
            </Link>
          </div>
        </div>
      </PageShell>
    )
  }

  return (
    <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
      <PageHeader title={t('heading')} description={t('subheading')} size="display" />

      <form
        onSubmit={handleSubmit}
        noValidate
        className="vp-card vp-sheet--ruled p-5 sm:p-6 flex flex-col gap-6 animate-vp-rise"
      >
        <fieldset className="flex flex-col gap-4 min-w-0" disabled={creating}>
          <legend className="flex items-center gap-2.5 text-vp-md font-semibold text-vp-ink mb-3">
            <span
              aria-hidden="true"
              className="flex size-6 items-center justify-center rounded-full bg-vp-ink text-vp-on-ink text-vp-2xs font-mono-num"
            >
              1
            </span>
            {t('accountLegend')}
          </legend>

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
            mono
          />
        </fieldset>

        <hr className="border-vp-border-subtle" />

        <fieldset className="flex flex-col gap-4 min-w-0" disabled={creating}>
          <legend className="flex items-center gap-2.5 text-vp-md font-semibold text-vp-ink mb-3">
            <span
              aria-hidden="true"
              className="flex size-6 items-center justify-center rounded-full bg-vp-ink text-vp-on-ink text-vp-2xs font-mono-num"
            >
              2
            </span>
            {t('firstBoardLegend')}
          </legend>

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
            mono
          />
        </fieldset>

        {generalError !== null && <Alert tone="error">{generalError}</Alert>}

        <div className="border-t border-vp-border-subtle bg-vp-surface-frost -mx-5 sm:-mx-6 -mb-5 sm:-mb-6 px-5 sm:px-6 py-4 rounded-b-[inherit]">
          <Button
            type="submit"
            variant="primary"
            size="lg"
            disabled={creating}
            loading={creating}
            aria-busy={creating}
          >
            {creating ? t('submitSubmitting') : t('submit')}
          </Button>
        </div>
      </form>
    </PageShell>
  )
}

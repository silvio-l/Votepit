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
 *
 * Terms/AUP/Privacy consent (board-operator-compliance ticket 04): a
 * required checkbox linking to the current Terms of Service, Acceptable
 * Use Policy and Privacy Policy pages on votepit.com (see termsUrls()
 * below) — submit stays disabled client-side until checked, and the
 * server independently rejects (422 `terms_not_accepted`) a submission
 * without it regardless of client-side state.
 *
 * Legal-operator identity (board-operator-compliance follow-up, extended
 * 2026-09-09 for international legal-notice completeness — see
 * SignupAccountAction's class doc): operator name, address and contact
 * email are all mandatory here too, same as the account settings page's
 * "Board operator details" section — the server rejects (422, field
 * errors) a signup missing any of the three. `legal_operator_notice`
 * (jurisdiction-specific extras) is deliberately NOT collected at signup —
 * it stays optional and can be added later on the account settings page.
 */

import { Fieldset } from '@votepit/ui'
import {
  Button,
  Checkbox,
  InlineNotice,
  LoadingState,
  Section,
  Steps,
  TextArea,
  TextField,
} from '@votepit/ui/ds'
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthHeading, AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import {
  bootstrap,
  captureReferral,
  createSignupAccount,
  getSignupStatus,
  logout,
} from '../lib/api'
import type { Language } from '../lib/i18n/context'
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

/**
 * Terms/AUP/Privacy page URLs on the marketing site (votepit.com), by
 * language — mirrors web/src/pages/{terms,acceptable-use,privacy}.astro
 * (EN) and web/src/pages/de/{agb,nutzungsrichtlinie,datenschutz}.astro (DE).
 * Not sourced from the `legal_links` bootstrap feature: that list is
 * operator-configured free-form Impressum/AGB/… footer links (and
 * currently carries no AUP entry at all) — the signup consent checkbox
 * needs the three SPECIFIC documents it asks the user to accept, always
 * these fixed marketing-site URLs regardless of `legal_links` config.
 */
function termsUrls(language: Language): { agb: string; aup: string; privacy: string } {
  return language === 'de'
    ? {
        agb: 'https://votepit.com/de/agb',
        aup: 'https://votepit.com/de/nutzungsrichtlinie',
        privacy: 'https://votepit.com/de/datenschutz',
      }
    : {
        agb: 'https://votepit.com/terms',
        aup: 'https://votepit.com/acceptable-use',
        privacy: 'https://votepit.com/privacy',
      }
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
  const [legalOperatorName, setLegalOperatorName] = useState('')
  const [legalOperatorAddress, setLegalOperatorAddress] = useState('')
  const [legalOperatorEmail, setLegalOperatorEmail] = useState('')
  const [boardName, setBoardName] = useState('')
  const [boardSlug, setBoardSlug] = useState('')
  const [boardSlugEdited, setBoardSlugEdited] = useState(false)
  const [termsAccepted, setTermsAccepted] = useState(false)

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
        legal_operator_name: legalOperatorName.trim(),
        legal_operator_address: legalOperatorAddress.trim(),
        legal_operator_email: legalOperatorEmail.trim(),
        board_name: boardName.trim(),
        board_slug: boardSlug.trim(),
        terms_accepted: termsAccepted,
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
      <AuthShell>
        <LoadingState label={t('loading')} rows={4} />
      </AuthShell>
    )
  }

  if (pageState.phase === 'error') {
    return (
      <AuthShell>
        <AuthOutcome tone="error" title={tCommon('state.errorTitle')} headingLevel="h1">
          {pageState.message}
        </AuthOutcome>
      </AuthShell>
    )
  }

  if (pageState.phase === 'already_has_account') {
    return (
      <AuthShell>
        <AuthOutcome
          tone="success"
          title={t('alreadyHasAccountTitle')}
          headingLevel="h1"
          action={
            pageState.accountSlug !== null ? (
              <Link
                to={`/${pageState.accountSlug}/admin/boards`}
                data-testid="signup-account-manage-link"
                className="ds-button ds-button--accent ds-focusable w-full"
              >
                {t('manageAccountCta')}
              </Link>
            ) : undefined
          }
        >
          {t('alreadyHasAccountBody')}
        </AuthOutcome>
      </AuthShell>
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
              data-testid="signup-account-manage-link"
              className="ds-button ds-button--accent ds-focusable w-full"
            >
              {t('manageAccountCta')}
            </Link>
          }
        >
          <p>
            {t('doneBodyBeforePath')}{' '}
            <Link
              to={boardPath}
              data-testid="signup-account-board-link"
              className="font-mono-num font-medium text-vp-ink underline"
            >
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

  const urls = termsUrls(language)

  return (
    <AuthShell
      footer={
        isAuthenticated ? (
          <Button
            type="button"
            variant="text"
            onClick={handleLogout}
            disabled={creating}
            testId="signup-account-logout"
          >
            {tCommon('header.logout')}
          </Button>
        ) : undefined
      }
    >
      <AuthHeading title={t('heading')} intro={t('subheading')} />
      <Steps items={stepItems} current={1} ariaLabel={t('stepsAriaLabel')} className="mb-6" />

      <form
        id="signup-account-form"
        onSubmit={handleSubmit}
        noValidate
        className="flex flex-col gap-6"
      >
        <Fieldset legend={t('accountLegend')}>
          <TextField
            label={t('nameLabel')}
            name="account_name"
            id="signup-account-name"
            value={accountName}
            onChange={(e) => handleAccountNameChange(e.target.value)}
            placeholder={t('accountNamePlaceholder')}
            error={fieldErrors.account_name}
            required
            testId="signup-account-name-input"
          />

          <TextField
            label={t('slugLabel')}
            name="account_slug"
            id="signup-account-slug"
            value={accountSlug}
            onChange={(e) => handleAccountSlugChange(e.target.value)}
            placeholder={t('accountSlugPlaceholder')}
            hint={
              fieldErrors.account_slug === undefined
                ? t('accountSlugHint', {
                    url: `${window.location.host}/${accountSlug.trim() || '…'}`,
                  })
                : undefined
            }
            error={fieldErrors.account_slug}
            required
            disabled={creating}
            mono
            testId="signup-account-slug-input"
          />
        </Fieldset>

        <Section
          title={t('legalOperatorLegend')}
          description={t('legalOperatorLegendHint')}
          testId="signup-account-legal-section"
        >
          <div className="flex flex-col gap-4">
            <TextField
              label={t('legalOperatorNameLabel')}
              name="legal_operator_name"
              id="signup-legal-operator-name"
              value={legalOperatorName}
              onChange={(e) => setLegalOperatorName(e.target.value)}
              placeholder={t('legalOperatorNamePlaceholder')}
              error={fieldErrors.legal_operator_name}
              required
              disabled={creating}
              maxLength={255}
              testId="signup-legal-operator-name-input"
            />

            <TextArea
              label={t('legalOperatorAddressLabel')}
              name="legal_operator_address"
              id="signup-legal-operator-address"
              value={legalOperatorAddress}
              onChange={(e) => setLegalOperatorAddress(e.target.value)}
              error={fieldErrors.legal_operator_address}
              placeholder={t('legalOperatorAddressPlaceholder')}
              required
              disabled={creating}
              rows={3}
              maxLength={2000}
              testId="signup-legal-operator-address-input"
            />

            <TextField
              label={t('legalOperatorEmailLabel')}
              name="legal_operator_email"
              id="signup-legal-operator-email"
              type="email"
              value={legalOperatorEmail}
              onChange={(e) => setLegalOperatorEmail(e.target.value)}
              error={fieldErrors.legal_operator_email}
              placeholder={t('legalOperatorEmailPlaceholder')}
              required
              disabled={creating}
              maxLength={255}
              testId="signup-legal-operator-email-input"
            />
          </div>
        </Section>

        <Fieldset legend={t('firstBoardLegend')}>
          <TextField
            label={t('nameLabel')}
            name="board_name"
            id="signup-board-name"
            value={boardName}
            onChange={(e) => handleBoardNameChange(e.target.value)}
            placeholder={t('boardNamePlaceholder')}
            error={fieldErrors.board_name}
            required
            testId="signup-board-name-input"
          />

          <TextField
            label={t('slugLabel')}
            name="board_slug"
            id="signup-board-slug"
            value={boardSlug}
            onChange={(e) => handleBoardSlugChange(e.target.value)}
            placeholder={t('boardSlugPlaceholder')}
            error={fieldErrors.board_slug}
            required
            disabled={creating}
            mono
            testId="signup-board-slug-input"
          />
        </Fieldset>

        <div className="flex flex-col gap-2">
          <Checkbox
            id="signup-terms-accepted"
            name="terms_accepted"
            testId="signup-terms-accepted"
            label={t('termsCheckboxLabel')}
            checked={termsAccepted}
            onChange={setTermsAccepted}
            disabled={creating}
          />
          <p className="text-vp-xs text-vp-text-muted pl-[26px] m-0">
            <a
              href={urls.agb}
              target="_blank"
              rel="noopener noreferrer"
              data-testid="signup-terms-agb"
              className="underline hover:text-vp-ink"
            >
              {t('termsLinkAgb')}
            </a>
            {' · '}
            <a
              href={urls.aup}
              target="_blank"
              rel="noopener noreferrer"
              data-testid="signup-terms-aup"
              className="underline hover:text-vp-ink"
            >
              {t('termsLinkAup')}
            </a>
            {' · '}
            <a
              href={urls.privacy}
              target="_blank"
              rel="noopener noreferrer"
              data-testid="signup-terms-privacy"
              className="underline hover:text-vp-ink"
            >
              {t('termsLinkPrivacy')}
            </a>
          </p>
        </div>

        {generalError !== null && (
          <InlineNotice tone="error" testId="signup-account-error">
            {generalError}
          </InlineNotice>
        )}

        <Button
          type="submit"
          form="signup-account-form"
          variant="accent"
          size="large"
          className="w-full"
          disabled={creating || !termsAccepted}
          loading={creating}
          testId="signup-account-submit"
        >
          {creating ? t('submitSubmitting') : t('submit')}
        </Button>
      </form>
    </AuthShell>
  )
}

/**
 * SignupPage — /signup
 *
 * Cloud onboarding, step 1 (cloud signup onboarding): email-first
 * entry point. Reuses the EXISTING magic-link mechanism verbatim (POST
 * /login, GET /login/verify) — no dedicated signup backend route for this
 * step. The only difference from a normal /login visit is the fixed
 * `r=/signup/account` return-to path: after the magic-link click,
 * VerifyPage's generic `navigate(data.redirect)` lands the (now-authenticated)
 * user on the account-name/slug picker instead of back at a board.
 *
 * Cloud-mode only in practice: self-host already operates exactly one,
 * pre-seeded account and has no use for this page (its own SPA never links
 * here) — the route is still safe to mount unconditionally client-side,
 * because the corresponding backend route (POST /signup/account) 404s/401s
 * in self-host anyway (AppFactory only registers it in cloud mode).
 *
 * Referral link capture (social-features ticket 01): a prospect arriving
 * from votepit.com/r/<account-slug> lands here with `?ref=<account-slug>`
 * — captureReferralRef() stashes it client-side (sessionStorage) so it
 * survives the magic-link round trip; SignupAccountPage consumes it once
 * the new account actually exists.
 */

import { Alert, Button, TextInput } from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { AuthHeading, AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { bootstrap, requestMagicLink } from '../lib/api'
import { useT } from '../lib/i18n/context'
import { captureReferralRef } from '../lib/referral'

const SIGNUP_RETURN_TO = '/signup/account'

type PageState =
  | { phase: 'idle' }
  | { phase: 'submitting' }
  | { phase: 'sent' }
  | { phase: 'error'; message: string }

export default function SignupPage() {
  const t = useT('signupPage')
  const tCommon = useT('common')
  const location = useLocation()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [state, setState] = useState<PageState>({ phase: 'idle' })

  // Seed CSRF token before any mutating request; skip the form entirely if a
  // valid session already exists — send them to their own boards/profile via
  // '/' (see RootRedirectPage) instead of re-running the magic-link flow.
  useEffect(() => {
    let cancelled = false
    bootstrap()
      .then((boot) => {
        if (!cancelled && boot.user) navigate('/', { replace: true })
      })
      .catch(() => {
        // Non-fatal — form will fail with a clear error if CSRF is missing.
      })
    return () => {
      cancelled = true
    }
  }, [navigate])

  // Referral link capture — see class doc above.
  useEffect(() => {
    captureReferralRef(location.search)
  }, [location.search])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (state.phase === 'submitting') return

    setState({ phase: 'submitting' })
    try {
      await requestMagicLink(email.trim(), SIGNUP_RETURN_TO)
      setState({ phase: 'sent' })
    } catch (err) {
      const apiErr = err as ApiError
      setState({
        phase: 'error',
        message: apiErr?.payload?.message ?? tCommon('state.error'),
      })
    }
  }

  const submitting = state.phase === 'submitting'

  return (
    <AuthShell
      footer={
        state.phase === 'sent' ? undefined : (
          <>
            {t('haveAccount')}{' '}
            {/* Plain /login, no r=/signup/account: this link exists for
                people who already have an account, so it must send them
                to their own boards afterwards (LoginPage's boardHome
                fallback), not back onto the signup step it just told them
                they don't need — see the "already has account" dead-end
                this used to produce. */}
            <Link to="/login" className="text-vp-accent-strong font-medium">
              {t('login')}
            </Link>
          </>
        )
      }
    >
      {state.phase === 'sent' ? (
        <AuthOutcome tone="success" title={t('sentHeading')} headingLevel="h1">
          <p>
            {t('sentBodyBeforeEmail')} <span className="font-medium text-vp-ink">{email}</span>{' '}
            {t('sentBodyAfterEmail')}
          </p>
          <p className="mt-2 text-vp-sm text-vp-text-muted">
            {t('noLinkArrived')}{' '}
            <Link to="/signup" className="text-vp-accent-strong underline">
              {t('retry')}
            </Link>
          </p>
        </AuthOutcome>
      ) : (
        <>
          <AuthHeading title={t('heading')} intro={t('subheading')} />

          <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
            <TextInput
              label={t('emailLabel')}
              type="email"
              name="email"
              id="signup-email"
              value={email}
              onChange={setEmail}
              placeholder={t('emailPlaceholder')}
              required
              disabled={submitting}
              autoComplete="email"
              inputMode="email"
            />

            {state.phase === 'error' && <Alert tone="error">{state.message}</Alert>}

            <Button
              type="submit"
              variant="primary"
              disabled={submitting || email.trim() === ''}
              loading={submitting}
              size="lg"
              block
            >
              {submitting ? t('submitSubmitting') : t('submit')}
            </Button>
          </form>
        </>
      )}
    </AuthShell>
  )
}

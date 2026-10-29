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

import { Button, Checkbox, InlineNotice, TextField } from '@votepit/ui/ds'
import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { AuthHeading, AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { bootstrap, requestMagicLink } from '../lib/api'
import { getFeatures } from '../lib/features'
import { useT } from '../lib/i18n/context'
import { captureReferralRef } from '../lib/referral'

const SIGNUP_RETURN_TO = '/signup/account'

/**
 * GET /login/oauth/{provider}/start — same real-navigation entry point
 * LoginPage.tsx uses, just with the signup return-to fixed to
 * SIGNUP_RETURN_TO so a brand-new OAuth user lands on the account-name/slug
 * picker exactly like a magic-link signup does.
 */
function oauthStartUrl(provider: 'google' | 'github'): string {
  return `/login/oauth/${provider}/start?r=${encodeURIComponent(SIGNUP_RETURN_TO)}`
}

type PageState =
  | { phase: 'idle' }
  | { phase: 'submitting' }
  | { phase: 'sent' }
  | { phase: 'error'; message: string }

export default function SignupPage() {
  const t = useT('signupPage')
  const tCommon = useT('common')
  const tConsent = useT('marketingConsent')
  const location = useLocation()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [state, setState] = useState<PageState>({ phase: 'idle' })
  // Product-news consent: unticked by default, shown only on a hosted install.
  const marketingOffered = getFeatures().marketing_consent === true
  const [productNews, setProductNews] = useState(false)
  const [oauthProviders, setOauthProviders] = useState<string[]>([])

  // Seed CSRF token before any mutating request; skip the form entirely if a
  // valid session already exists — no need to re-run the magic-link flow,
  // the visitor is already authenticated. Where they land next depends on
  // whether they already own an account: an owner goes to their own
  // boards/profile via '/' (see RootRedirectPage); a logged-in visitor with
  // no account yet (this page's CTA is reached from ProfilePage's opt-in
  // "create your own board" empty-state, see RootRedirectPage's class doc)
  // must go straight to the account-name/slug picker — sending them to '/'
  // instead would bounce them back to /profile (RootRedirectPage's
  // no-membership case), silently undoing the very button they just clicked.
  useEffect(() => {
    let cancelled = false
    bootstrap()
      .then((boot) => {
        if (cancelled) return
        if (boot.user) {
          navigate(boot.user.memberships.length > 0 ? '/' : SIGNUP_RETURN_TO, { replace: true })
          return
        }
        setOauthProviders(boot.oauth_providers)
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
      await requestMagicLink(
        email.trim(),
        SIGNUP_RETURN_TO,
        marketingOffered ? productNews : undefined,
      )
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
            <Link
              to="/login"
              data-testid="signup-login-link"
              className="text-vp-title font-medium underline underline-offset-4"
            >
              {t('login')}
            </Link>
          </>
        )
      }
    >
      {state.phase === 'sent' ? (
        <AuthOutcome tone="success" title={t('sentHeading')} headingLevel="h1">
          <p>
            {t('sentBodyBeforeEmail')} <span className="font-medium text-vp-title">{email}</span>{' '}
            {t('sentBodyAfterEmail')}
          </p>
          <p className="mt-2 text-vp-sm text-vp-text-muted">
            {t('noLinkArrived')}{' '}
            <Link
              to="/signup"
              data-testid="signup-retry-link"
              className="text-vp-title font-medium underline underline-offset-4"
            >
              {t('retry')}
            </Link>
          </p>
        </AuthOutcome>
      ) : (
        <>
          <AuthHeading title={t('heading')} intro={t('subheading')} />

          <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
            <TextField
              label={t('emailLabel')}
              type="email"
              name="email"
              id="signup-email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={t('emailPlaceholder')}
              required
              disabled={submitting}
              autoComplete="email"
              inputMode="email"
              testId="signup-email-input"
            />

            {marketingOffered && (
              <div className="flex flex-col gap-2">
                <Checkbox
                  id="signup-product-news"
                  name="product_news"
                  testId="signup-product-news"
                  label={tConsent('signupCheckboxLabel')}
                  checked={productNews}
                  onChange={setProductNews}
                  disabled={submitting}
                />
                <p
                  className="text-vp-xs text-vp-text-muted pl-[26px] m-0"
                  data-testid="signup-product-news-notice"
                >
                  {tConsent('signupNotice')}
                </p>
              </div>
            )}

            {state.phase === 'error' && (
              <InlineNotice tone="error" testId="signup-error">
                {state.message}
              </InlineNotice>
            )}

            <Button
              type="submit"
              variant="accent"
              size="large"
              className="w-full"
              disabled={submitting || email.trim() === ''}
              loading={submitting}
              testId="signup-submit"
            >
              {submitting ? t('submitSubmitting') : t('submit')}
            </Button>
          </form>

          {oauthProviders.length > 0 && (
            <div className="mt-5 flex flex-col gap-3">
              <div className="flex items-center gap-3 text-vp-sm text-vp-text-muted">
                <span className="h-px flex-1 bg-vp-border" />
                {t('oauthDivider')}
                <span className="h-px flex-1 bg-vp-border" />
              </div>
              {oauthProviders.includes('google') && (
                <a
                  href={oauthStartUrl('google')}
                  data-testid="signup-oauth-google"
                  className="ds-button ds-button--secondary ds-focusable w-full"
                >
                  {t('oauthContinueWithGoogle')}
                </a>
              )}
              {oauthProviders.includes('github') && (
                <a
                  href={oauthStartUrl('github')}
                  data-testid="signup-oauth-github"
                  className="ds-button ds-button--secondary ds-focusable w-full"
                >
                  {t('oauthContinueWithGithub')}
                </a>
              )}
            </div>
          )}
        </>
      )}
    </AuthShell>
  )
}

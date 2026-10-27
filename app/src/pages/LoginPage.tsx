import { appExtensions } from '@votepit/app-extensions'
import { Button, InlineNotice, Tabs, TextField } from '@votepit/ui/ds'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AuthHeading, AuthOutcome, AuthShell } from '../components/AuthShell'
import { TwoFactorStep } from '../components/TwoFactorStep'
import { getRoutingMode } from '../lib/accountContext'
import type { ApiError } from '../lib/api'
import { bootstrap, loginWithPassword, requestMagicLink } from '../lib/api'
import { useT } from '../lib/i18n/context'

type LoginMethod = 'magic_link' | 'password'

type PageState =
  | { phase: 'idle' }
  | { phase: 'submitting' }
  | { phase: 'sent' }
  | { phase: 'error'; message: string }
  | { phase: 'requires_2fa'; pendingToken: string }

/**
 * Fixed, context-bound back target: ALWAYS the board wall — never browser
 * history. Derived from returnTo:
 *   /acme → /acme · /acme/idea/5 → /acme · /admin/boards/acme → /acme
 *   /invite/accept?token=… → / (a global route, not a board)
 */
function deriveBoardHome(returnTo?: string): string {
  if (!returnTo) return '/'
  const parts = returnTo.replace(/^\/+/, '').split('/')
  if (parts[0] === 'invite') return '/'
  if (parts[0] === 'admin' && parts[1] === 'boards' && parts[2]) return `/${parts[2]}`
  if (parts[0] && parts[0] !== 'login') return `/${parts[0]}`
  return '/'
}

/**
 * GET /login/oauth/{provider}/start — a real browser navigation (302 to the
 * provider), never a fetch(): OAuthStartAction persists `r` server-side
 * (OAuthStateRepository) alongside the state/PKCE pair, so it survives the
 * round trip to the provider and back without the client re-sending it at
 * the callback (which would be untrusted input).
 */
function oauthStartUrl(provider: 'google' | 'github', returnTo?: string): string {
  return `/login/oauth/${provider}/start${returnTo ? `?r=${encodeURIComponent(returnTo)}` : ''}`
}

export default function LoginPage() {
  const t = useT('loginPage')
  const tCommon = useT('common')
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const returnTo = searchParams.get('r') ?? undefined
  const boardHome = deriveBoardHome(returnTo)
  // InviteAcceptPage sends anonymous invitees here — without saying why,
  // a bare login form reads as a broken invite link.
  const isInviteReturn = returnTo?.startsWith('/invite/accept') ?? false
  // /signup creates a new account (tenant) and only exists in cloud mode —
  // an invitee must NOT take that path (they'd get their own account instead
  // of joining the inviting one; the invite notice covers them instead).
  const showSignupLink = getRoutingMode() === 'cloud' && !isInviteReturn
  // OAuthCallbackAction hands both of these off via the URL, not a fetch()
  // response — it's reached by a real browser navigation (the provider
  // redirects here directly), so there's no SPA JS call whose result could
  // carry them instead.
  const pendingTokenFromOAuth = searchParams.get('pending_token')
  const oauthError = searchParams.get('oauth_error') !== null

  const [method, setMethod] = useState<LoginMethod>('magic_link')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [state, setState] = useState<PageState>(
    pendingTokenFromOAuth
      ? { phase: 'requires_2fa', pendingToken: pendingTokenFromOAuth }
      : oauthError
        ? { phase: 'error', message: tCommon('state.error') }
        : { phase: 'idle' },
  )
  const [oauthProviders, setOauthProviders] = useState<string[]>([])

  // Seed CSRF token before any mutating request; skip the form entirely if
  // a valid session already exists (e.g. landing page → /login while logged in).
  useEffect(() => {
    let cancelled = false
    bootstrap()
      .then((boot) => {
        if (cancelled) return
        if (boot.user) {
          navigate(boardHome, { replace: true })
          return
        }
        setOauthProviders(boot.oauth_providers ?? [])
      })
      .catch(() => {
        // Non-fatal — form will fail with a clear error if CSRF is missing.
      })
    return () => {
      cancelled = true
    }
  }, [navigate, boardHome])

  const handleMagicLinkSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (state.phase === 'submitting') return

    setState({ phase: 'submitting' })
    try {
      await requestMagicLink(email.trim(), returnTo)
      setState({ phase: 'sent' })
    } catch (err) {
      const apiErr = err as ApiError
      setState({
        phase: 'error',
        message:
          apiErr?.payload?.key === 'rate_limited'
            ? tCommon('state.rateLimited')
            : (apiErr?.payload?.message ?? tCommon('state.error')),
      })
    }
  }

  const handlePasswordSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (state.phase === 'submitting') return

    setState({ phase: 'submitting' })
    try {
      const result = await loginWithPassword(email.trim(), password, returnTo)
      if ('requires_2fa' in result) {
        setState({ phase: 'requires_2fa', pendingToken: result.pending_token })
        return
      }
      navigate(result.redirect || boardHome, { replace: true })
    } catch (err) {
      const apiErr = err as ApiError
      setState({
        phase: 'error',
        message:
          apiErr?.payload?.key === 'rate_limited'
            ? tCommon('state.rateLimited')
            : (apiErr?.payload?.message ?? tCommon('state.error')),
      })
    }
  }

  const submitting = state.phase === 'submitting'

  if (state.phase === 'requires_2fa') {
    return (
      <AuthShell>
        <TwoFactorStep
          pendingToken={state.pendingToken}
          returnTo={returnTo}
          onSuccess={(redirect) => navigate(redirect || boardHome, { replace: true })}
        />
      </AuthShell>
    )
  }

  return (
    <AuthShell
      footer={
        showSignupLink && state.phase !== 'sent' ? (
          <>
            {t('noAccount')}{' '}
            <Link
              to="/signup"
              data-testid="login-signup-link"
              className="text-vp-ink font-medium underline underline-offset-4"
            >
              {t('signupLink')}
            </Link>
          </>
        ) : undefined
      }
    >
      {state.phase === 'sent' ? (
        <AuthOutcome tone="success" title={t('sentTitle')} headingLevel="h1">
          <p>
            {t('sentBefore')} <span className="font-medium text-vp-ink">{email}</span>{' '}
            {t('sentAfter')}
          </p>
          <p className="mt-2 text-vp-sm text-vp-text-muted">
            {t('noLinkQuestion')}{' '}
            <Link
              to="/login"
              data-testid="login-retry-link"
              className="text-vp-ink font-medium underline underline-offset-4"
            >
              {t('retryLink')}
            </Link>
          </p>
        </AuthOutcome>
      ) : (
        <>
          <AuthHeading
            title={t('heading')}
            intro={method === 'magic_link' ? t('subtitle') : t('subtitlePassword')}
          />

          {isInviteReturn && (
            <InlineNotice
              tone="info"
              title={t('inviteNoticeTitle')}
              testId="login-invite-notice"
              className="mb-5"
            >
              {t('inviteNoticeBody')}
            </InlineNotice>
          )}

          <Tabs
            items={[
              { value: 'magic_link', label: t('methodMagicLink') },
              { value: 'password', label: t('methodPassword') },
            ]}
            value={method}
            onChange={setMethod}
            ariaLabel={t('methodTabsLabel')}
            testIdPrefix="login-method"
            className="mb-5"
          />

          {method === 'magic_link' ? (
            <form onSubmit={handleMagicLinkSubmit} noValidate className="flex flex-col gap-4">
              <TextField
                label={t('emailLabel')}
                type="email"
                name="email"
                id="login-email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder={t('emailPlaceholder')}
                required
                disabled={submitting}
                autoComplete="email"
                inputMode="email"
                testId="login-email-input"
              />

              {state.phase === 'error' && (
                <InlineNotice tone="error" testId="login-error">
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
                testId="login-submit-magic-link"
              >
                {submitting ? t('submitting') : t('submit')}
              </Button>
            </form>
          ) : (
            <form onSubmit={handlePasswordSubmit} noValidate className="flex flex-col gap-4">
              <TextField
                label={t('emailLabel')}
                type="email"
                name="email"
                id="login-password-email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder={t('emailPlaceholder')}
                required
                disabled={submitting}
                autoComplete="email"
                inputMode="email"
                testId="login-password-email-input"
              />
              <TextField
                label={t('passwordLabel')}
                type="password"
                name="password"
                id="login-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                disabled={submitting}
                autoComplete="current-password"
                testId="login-password-input"
              />

              {state.phase === 'error' && (
                <InlineNotice tone="error" testId="login-error">
                  {state.message}
                </InlineNotice>
              )}

              <Button
                type="submit"
                variant="accent"
                size="large"
                className="w-full"
                disabled={submitting || email.trim() === '' || password === ''}
                loading={submitting}
                testId="login-submit-password"
              >
                {submitting ? t('passwordSubmitting') : t('passwordSubmit')}
              </Button>

              <Link
                to="/password/reset/request"
                data-testid="login-forgot-password-link"
                className="text-vp-sm text-vp-ink font-medium underline underline-offset-4 self-start"
              >
                {t('forgotPasswordLink')}
              </Link>
            </form>
          )}

          {oauthProviders.length > 0 && (
            <div className="mt-5 flex flex-col gap-3">
              <div className="flex items-center gap-3 text-vp-sm text-vp-text-muted">
                <span className="h-px flex-1 bg-vp-border" />
                {t('oauthDivider')}
                <span className="h-px flex-1 bg-vp-border" />
              </div>
              {oauthProviders.includes('google') && (
                <a
                  href={oauthStartUrl('google', returnTo)}
                  data-testid="login-oauth-google"
                  className="ds-button ds-button--secondary ds-focusable w-full"
                >
                  {t('oauthContinueWithGoogle')}
                </a>
              )}
              {oauthProviders.includes('github') && (
                <a
                  href={oauthStartUrl('github', returnTo)}
                  data-testid="login-oauth-github"
                  className="ds-button ds-button--secondary ds-focusable w-full"
                >
                  {t('oauthContinueWithGithub')}
                </a>
              )}
            </div>
          )}

          {/* SPA extensions' login-footer slot — e.g. an alternative sign-in. */}
          {appExtensions.slots.loginFooter}
        </>
      )}
    </AuthShell>
  )
}

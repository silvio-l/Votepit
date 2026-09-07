import { appExtensions } from '@votepit/app-extensions'
import { Alert, BackLink, Button, buttonClassName, Tabs, TextInput } from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AuthHeading, AuthOutcome, AuthShell } from '../components/AuthShell'
import { TwoFactorStep } from '../components/TwoFactorStep'
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
 */
function deriveBoardHome(returnTo?: string): string {
  if (!returnTo) return '/'
  const parts = returnTo.replace(/^\/+/, '').split('/')
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

  const [method, setMethod] = useState<LoginMethod>('magic_link')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [state, setState] = useState<PageState>({ phase: 'idle' })
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
        setOauthProviders(boot.oauth_providers)
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
      back={
        <BackLink as={Link} to={boardHome}>
          {t('backToBoard')}
        </BackLink>
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
            <Link to="/login" className="text-vp-accent-strong underline">
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

          <Tabs
            items={[
              { value: 'magic_link', label: t('methodMagicLink') },
              { value: 'password', label: t('methodPassword') },
            ]}
            value={method}
            onChange={setMethod}
            ariaLabel={t('methodTabsLabel')}
            variant="segmented"
            className="mb-5"
          />

          {method === 'magic_link' ? (
            <form onSubmit={handleMagicLinkSubmit} noValidate className="flex flex-col gap-4">
              <TextInput
                label={t('emailLabel')}
                type="email"
                name="email"
                id="login-email"
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
                {submitting ? t('submitting') : t('submit')}
              </Button>
            </form>
          ) : (
            <form onSubmit={handlePasswordSubmit} noValidate className="flex flex-col gap-4">
              <TextInput
                label={t('emailLabel')}
                type="email"
                name="email"
                id="login-password-email"
                value={email}
                onChange={setEmail}
                placeholder={t('emailPlaceholder')}
                required
                disabled={submitting}
                autoComplete="email"
                inputMode="email"
              />
              <TextInput
                label={t('passwordLabel')}
                type="password"
                name="password"
                id="login-password"
                value={password}
                onChange={setPassword}
                required
                disabled={submitting}
                autoComplete="current-password"
              />

              {state.phase === 'error' && <Alert tone="error">{state.message}</Alert>}

              <Button
                type="submit"
                variant="primary"
                disabled={submitting || email.trim() === '' || password === ''}
                loading={submitting}
                size="lg"
                block
              >
                {submitting ? t('passwordSubmitting') : t('passwordSubmit')}
              </Button>

              <Link
                to="/password/reset/request"
                className="text-vp-sm text-vp-accent-strong self-start"
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
                  className={buttonClassName('secondary', 'lg', 'w-full')}
                >
                  {t('oauthContinueWithGoogle')}
                </a>
              )}
              {oauthProviders.includes('github') && (
                <a
                  href={oauthStartUrl('github', returnTo)}
                  className={buttonClassName('secondary', 'lg', 'w-full')}
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

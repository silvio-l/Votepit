import { Button, InlineNotice, TextField } from '@votepit/ui/ds'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { AuthHeading, AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { bootstrap, requestPasswordReset } from '../lib/api'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'idle' }
  | { phase: 'submitting' }
  | { phase: 'sent' }
  | { phase: 'error'; message: string }

/**
 * "Forgot password" — step A. GET /password/reset/request (SPA page, served
 * for GET; the same path also carries the POST API action — see AppFactory).
 *
 * The backend's response is ALWAYS {ok: true} regardless of whether the
 * email matches an account (anti-enumeration — see PasswordResetRequestAction
 * class doc). This page mirrors that: any successful call — real account or
 * not — renders the identical "check your inbox" outcome. Only a genuine
 * infra failure (rate limit, CSRF, network) surfaces as an error, and none of
 * those reveal anything about account existence either.
 */
export default function ForgotPasswordPage() {
  const t = useT('forgotPasswordPage')
  const tCommon = useT('common')
  const [email, setEmail] = useState('')
  const [state, setState] = useState<PageState>({ phase: 'idle' })

  // Seed CSRF token before the mutating request.
  useEffect(() => {
    bootstrap().catch(() => {
      // Non-fatal — the form will fail with a clear error if CSRF is missing.
    })
  }, [])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (state.phase === 'submitting') return

    setState({ phase: 'submitting' })
    try {
      await requestPasswordReset(email.trim())
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

  const submitting = state.phase === 'submitting'

  return (
    <AuthShell
      footer={
        state.phase === 'sent' ? undefined : (
          <Link
            to="/login"
            data-testid="forgot-back-to-login"
            className="text-vp-title font-medium underline underline-offset-4"
          >
            {t('backToLogin')}
          </Link>
        )
      }
    >
      {state.phase === 'sent' ? (
        <AuthOutcome tone="success" title={t('sentTitle')} headingLevel="h1">
          <p>{t('sentBody')}</p>
        </AuthOutcome>
      ) : (
        <>
          <AuthHeading title={t('heading')} intro={t('subtitle')} />

          <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
            <TextField
              label={t('emailLabel')}
              type="email"
              name="email"
              id="forgot-password-email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={t('emailPlaceholder')}
              required
              disabled={submitting}
              autoComplete="email"
              inputMode="email"
              testId="forgot-email-input"
            />

            {state.phase === 'error' && (
              <InlineNotice tone="error" testId="forgot-error">
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
              testId="forgot-submit"
            >
              {submitting ? t('submitting') : t('submit')}
            </Button>
          </form>
        </>
      )}
    </AuthShell>
  )
}

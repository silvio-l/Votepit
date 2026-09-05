import { Alert, BackLink, Button, TextInput } from '@votepit/ui'
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
      back={
        <BackLink as={Link} to="/login">
          {t('backToLogin')}
        </BackLink>
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
            <TextInput
              label={t('emailLabel')}
              type="email"
              name="email"
              id="forgot-password-email"
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
        </>
      )}
    </AuthShell>
  )
}

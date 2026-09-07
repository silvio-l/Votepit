import { buttonClassName } from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { confirmNotificationEmail } from '../lib/api'
import { useT } from '../lib/i18n/context'

type PageState = { phase: 'confirming' } | { phase: 'done' } | { phase: 'error'; message: string }

/**
 * GET /account/notification-email/confirm?token=<plaintext> (SPA page; the
 * same path also carries the GET API action — see NotificationPreferencesAction).
 * Path MUST match what the backend emails:
 * $config->appUrl . '/account/notification-email/confirm?token=' . $pair['token'].
 * Requires an existing login session (AuthZ: user) — an invalid/expired/
 * wrong-user token collapses into one generic error (fail-secure, mirrors
 * confirmEmail's constant-shape failure).
 */
export default function NotificationEmailConfirmPage() {
  const [searchParams] = useSearchParams()
  const t = useT('notificationEmailConfirmPage')
  const token = searchParams.get('token') ?? ''

  const [state, setState] = useState<PageState>({ phase: 'confirming' })

  // biome-ignore lint/correctness/useExhaustiveDependencies: run once on mount; token is stable from the URL.
  useEffect(() => {
    if (!token) {
      setState({ phase: 'error', message: t('invalidOrExpired') })
      return
    }

    confirmNotificationEmail(token)
      .then(() => setState({ phase: 'done' }))
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const isInvalidToken = apiErr?.payload?.key === 'invalid_token' || apiErr?.status === 400

        setState({
          phase: 'error',
          message: isInvalidToken ? t('invalidOrExpired') : t('confirmFailedFallback'),
        })
      })
  }, [])

  return (
    <AuthShell>
      {state.phase === 'confirming' && (
        <AuthOutcome tone="pending">
          <span aria-busy="true">{t('confirming')}</span>
        </AuthOutcome>
      )}
      {state.phase === 'done' && (
        <AuthOutcome
          tone="success"
          title={t('doneTitle')}
          headingLevel="h1"
          action={
            <Link to="/profile" className={buttonClassName('primary', 'lg', 'w-full')}>
              {t('backToProfile')}
            </Link>
          }
        >
          <p>{t('doneBody')}</p>
        </AuthOutcome>
      )}
      {state.phase === 'error' && (
        <AuthOutcome
          tone="error"
          title={t('errorTitle')}
          headingLevel="h1"
          action={
            <Link to="/profile" className={buttonClassName('primary', 'lg', 'w-full')}>
              {t('backToProfile')}
            </Link>
          }
        >
          {state.message}
        </AuthOutcome>
      )}
    </AuthShell>
  )
}

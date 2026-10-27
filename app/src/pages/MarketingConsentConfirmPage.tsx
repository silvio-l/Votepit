import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { confirmMarketingConsent } from '../lib/api'
import { useT } from '../lib/i18n/context'

type PageState = { phase: 'confirming' } | { phase: 'done' } | { phase: 'error'; message: string }

/**
 * GET /account/marketing-consent/confirm?token=<plaintext> — the link from the
 * product-news confirmation mail (double opt-in from the profile). Path MUST
 * match what the backend emails (MarketingConsentAction::toggle). Requires an
 * existing login session; an invalid, expired or foreign token collapses into
 * one generic error.
 */
export default function MarketingConsentConfirmPage() {
  const [searchParams] = useSearchParams()
  const t = useT('marketingConsent')
  const token = searchParams.get('token') ?? ''

  const [state, setState] = useState<PageState>({ phase: 'confirming' })

  // biome-ignore lint/correctness/useExhaustiveDependencies: run once on mount; token is stable from the URL.
  useEffect(() => {
    if (!token) {
      setState({ phase: 'error', message: t('invalidOrExpired') })
      return
    }

    confirmMarketingConsent(token)
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
            <Link
              to="/profile"
              className="ds-button ds-button--accent ds-focusable w-full"
              data-testid="marketing-consent-confirm-done-link"
            >
              {t('backToProfile')}
            </Link>
          }
        >
          <p>{t('doneBody')}</p>
        </AuthOutcome>
      )}
      {state.phase === 'error' && (
        <AuthOutcome
          tone="info"
          title={t('errorTitle')}
          headingLevel="h1"
          action={
            <Link
              to="/profile"
              className="ds-button ds-button--accent ds-focusable w-full"
              data-testid="marketing-consent-confirm-error-link"
            >
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

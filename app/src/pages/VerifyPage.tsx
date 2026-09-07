import { buttonClassName } from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import { TwoFactorStep } from '../components/TwoFactorStep'
import type { ApiError } from '../lib/api'
import { verifyToken } from '../lib/api'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'verifying' }
  | { phase: 'error'; message: string }
  | { phase: 'requires_2fa'; pendingToken: string }

export default function VerifyPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const t = useT('verifyPage')
  const token = searchParams.get('token') ?? ''
  const returnTo = searchParams.get('r') ?? undefined

  const [state, setState] = useState<PageState>({ phase: 'verifying' })

  // biome-ignore lint/correctness/useExhaustiveDependencies: run once on mount; token / returnTo are stable from the URL.
  useEffect(() => {
    if (!token) {
      setState({
        phase: 'error',
        message: t('invalidOrExpired'),
      })
      return
    }

    verifyToken(token, returnTo)
      .then((data) => {
        if ('requires_2fa' in data) {
          setState({ phase: 'requires_2fa', pendingToken: data.pending_token })
          return
        }
        // Session cookie set by server; navigate to the redirect target.
        navigate(data.redirect, { replace: true })
      })
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const isInvalidToken = apiErr?.payload?.key === 'invalid_token' || apiErr?.status === 400

        setState({
          phase: 'error',
          message: isInvalidToken
            ? t('invalidOrExpiredRetry')
            : (apiErr?.payload?.message ?? t('loginFailedFallback')),
        })
      })
  }, []) // run once on mount; token / returnTo are stable from URL

  if (state.phase === 'requires_2fa') {
    return (
      <AuthShell>
        <TwoFactorStep
          pendingToken={state.pendingToken}
          returnTo={returnTo}
          onSuccess={(redirect) => navigate(redirect, { replace: true })}
        />
      </AuthShell>
    )
  }

  return (
    <AuthShell>
      {state.phase === 'verifying' ? (
        <AuthOutcome tone="pending">
          <span aria-busy="true">{t('verifying')}</span>
        </AuthOutcome>
      ) : (
        <AuthOutcome
          tone="error"
          title={t('errorTitle')}
          headingLevel="h1"
          action={
            <Link to="/login" className={buttonClassName('primary', 'lg', 'w-full')}>
              {t('requestNewLink')}
            </Link>
          }
        >
          {state.message}
        </AuthOutcome>
      )}
    </AuthShell>
  )
}

import { Button } from '@votepit/ui/ds'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { unsubscribeOperatorNudge } from '../lib/api/operatorNudges'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'confirm' }
  | { phase: 'working' }
  | { phase: 'done' }
  | { phase: 'error'; message: string; retryable: boolean }

/**
 * /operator-nudges/unsubscribe?token=<signed> — target of the operator nudge
 * mails' one-click unsubscribe link. Path MUST match what the backend emails:
 * $config->appUrl . '/operator-nudges/unsubscribe?token=' . sign() (OperatorNudgeDispatcher).
 * No login needed.
 *
 * The POST only happens after the visitor presses the button: mail scanners and
 * link previews fetch links without a click, and must never switch anything off.
 */
export default function OperatorNudgeUnsubscribePage() {
  const [searchParams] = useSearchParams()
  const t = useT('operatorNudgeUnsubscribePage')
  const token = searchParams.get('token') ?? ''

  const [state, setState] = useState<PageState>(() =>
    token === ''
      ? { phase: 'error', message: t('invalidLink'), retryable: false }
      : { phase: 'confirm' },
  )

  const confirm = () => {
    setState({ phase: 'working' })
    unsubscribeOperatorNudge(token)
      .then(() => setState({ phase: 'done' }))
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const isInvalid = apiErr?.payload?.key === 'invalid_token' || apiErr?.status === 400
        setState(
          isInvalid
            ? { phase: 'error', message: t('invalidLink'), retryable: false }
            : { phase: 'error', message: t('failedFallback'), retryable: true },
        )
      })
  }

  const profileLink = (
    <Link
      to="/profile"
      className="ds-button ds-button--accent ds-focusable w-full"
      data-testid="operator-nudge-profile-link"
    >
      {t('toProfile')}
    </Link>
  )

  return (
    <AuthShell>
      {state.phase === 'confirm' && (
        <AuthOutcome
          tone="info"
          title={t('confirmTitle')}
          headingLevel="h1"
          action={
            <Button
              testId="operator-nudge-unsubscribe-confirm"
              type="button"
              variant="primary"
              block
              onClick={confirm}
            >
              {t('confirmCta')}
            </Button>
          }
        >
          <p>{t('confirmBody')}</p>
        </AuthOutcome>
      )}
      {state.phase === 'working' && (
        <AuthOutcome tone="pending">
          <span aria-busy="true">{t('unsubscribing')}</span>
        </AuthOutcome>
      )}
      {state.phase === 'done' && (
        <AuthOutcome tone="success" title={t('doneTitle')} headingLevel="h1" action={profileLink}>
          <p>{t('doneBody')}</p>
        </AuthOutcome>
      )}
      {state.phase === 'error' && (
        <AuthOutcome
          tone="info"
          title={t('errorTitle')}
          headingLevel="h1"
          action={
            state.retryable ? (
              <Button
                testId="operator-nudge-unsubscribe-retry"
                type="button"
                variant="primary"
                block
                onClick={confirm}
              >
                {t('retryCta')}
              </Button>
            ) : (
              profileLink
            )
          }
        >
          {state.message}
        </AuthOutcome>
      )}
    </AuthShell>
  )
}

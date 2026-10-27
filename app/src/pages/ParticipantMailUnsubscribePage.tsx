import { Button } from '@votepit/ui/ds'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { unsubscribeParticipantMail } from '../lib/api/participantMail'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'confirm' }
  | { phase: 'working' }
  | { phase: 'done' }
  | { phase: 'error'; message: string; retryable: boolean }

/**
 * /mail/unsubscribe?token=<signed> — target of the one-click unsubscribe link in
 * the participant mails (close-the-loop re-engagement). No login needed.
 *
 * The POST only happens after the visitor presses the button: mail scanners and
 * link previews fetch links without a click, and must never switch mails off.
 */
export default function ParticipantMailUnsubscribePage() {
  const [searchParams] = useSearchParams()
  const t = useT('participantMailUnsubscribePage')
  const token = searchParams.get('token') ?? ''

  const [state, setState] = useState<PageState>(() =>
    token === ''
      ? { phase: 'error', message: t('invalidLink'), retryable: false }
      : { phase: 'confirm' },
  )

  const confirm = () => {
    setState({ phase: 'working' })
    unsubscribeParticipantMail(token)
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
      data-testid="participant-mail-profile-link"
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
              testId="participant-mail-unsubscribe-confirm"
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
                testId="participant-mail-unsubscribe-retry"
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

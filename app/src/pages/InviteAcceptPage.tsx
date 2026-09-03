/**
 * InviteAcceptPage — /invite/accept?token=…
 *
 * Accepts a pending account invite. Mirrors VerifyPage's
 * verify-on-mount pattern (GET /invite/accept is CSRF-exempt — the token
 * itself is the capability, same as /login/verify).
 *
 * Anon visitor: GET /admin/invites/accept returns 401 → redirect to
 * /login?r=/invite/accept?token=… so the invitee logs in (magic link) with
 * the invited address, then lands back here already authenticated.
 * Wrong session (logged in as someone else): 403 invite_mismatch → shown as
 * its own state with a targeted "log out and switch" way forward, not folded
 * into the generic invalid/expired error (different cause, different fix).
 */

import { Button, buttonClassName } from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { acceptInvite, logout } from '../lib/api'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'accepting' }
  | { phase: 'error'; message: string }
  | { phase: 'mismatch'; message: string }
  | { phase: 'done'; accountSlug: string | null }

export default function InviteAcceptPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const t = useT('inviteAcceptPage')
  const token = searchParams.get('token') ?? ''
  const [switchingAccount, setSwitchingAccount] = useState(false)

  const [state, setState] = useState<PageState>({ phase: 'accepting' })

  const loginReturnTo = `/invite/accept?token=${token}`

  // biome-ignore lint/correctness/useExhaustiveDependencies: run once on mount; token is stable from the URL.
  useEffect(() => {
    if (!token) {
      setState({ phase: 'error', message: t('invalidOrExpired') })
      return
    }

    acceptInvite(token)
      .then((res) => {
        setState({ phase: 'done', accountSlug: res.account_slug })
      })
      .catch((err: unknown) => {
        const apiErr = err as ApiError

        if (apiErr?.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(loginReturnTo)}`, { replace: true })
          return
        }

        if (apiErr?.name === 'ApiError' && apiErr.payload?.key === 'invite_mismatch') {
          setState({ phase: 'mismatch', message: apiErr.payload.message ?? t('mismatchBody') })
          return
        }

        setState({
          phase: 'error',
          message: apiErr?.payload?.message ?? t('acceptFailedFallback'),
        })
      })
  }, []) // run once on mount; token is stable from the URL

  const handleSwitchAccount = async () => {
    if (switchingAccount) return
    setSwitchingAccount(true)
    try {
      await logout()
    } finally {
      navigate(`/login?r=${encodeURIComponent(loginReturnTo)}`)
    }
  }

  // Built directly from the accept response, not accountPath(): this page
  // renders under GlobalLayout (no :accountSlug param yet in the URL).
  const membersPath =
    state.phase === 'done' && state.accountSlug !== null
      ? `/${state.accountSlug}/admin/members`
      : '/admin/members'

  return (
    <AuthShell>
      {state.phase === 'accepting' && (
        <AuthOutcome tone="pending">
          <span aria-live="polite" aria-busy="true">
            {t('accepting')}
          </span>
        </AuthOutcome>
      )}
      {state.phase === 'done' && (
        <AuthOutcome
          tone="success"
          title={t('doneTitle')}
          headingLevel="h1"
          action={
            <Link to={membersPath} className={buttonClassName('primary', 'lg', 'w-full')}>
              {t('goToMembers')}
            </Link>
          }
        >
          {t('doneBody')}
        </AuthOutcome>
      )}
      {state.phase === 'mismatch' && (
        <AuthOutcome
          tone="error"
          title={t('mismatchTitle')}
          headingLevel="h1"
          action={
            <Button
              variant="primary"
              size="lg"
              block
              onClick={handleSwitchAccount}
              loading={switchingAccount}
              disabled={switchingAccount}
            >
              {switchingAccount ? t('switchingAccount') : t('switchAccountCta')}
            </Button>
          }
        >
          {state.message}
        </AuthOutcome>
      )}
      {state.phase === 'error' && (
        <AuthOutcome
          tone="error"
          title={t('errorTitle')}
          headingLevel="h1"
          action={
            <Link to="/" className={buttonClassName('primary', 'lg', 'w-full')}>
              {t('goToHome')}
            </Link>
          }
        >
          {state.message}
        </AuthOutcome>
      )}
    </AuthShell>
  )
}

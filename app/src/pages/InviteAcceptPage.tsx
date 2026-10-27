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

import { Button } from '@votepit/ui/ds'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import { getRoutingMode } from '../lib/accountContext'
import type { AccountRole, ApiError } from '../lib/api'
import { acceptInvite, bootstrap, logout } from '../lib/api'
import { useT } from '../lib/i18n/context'

const DONE_BODY_KEY = {
  owner: 'doneBodyOwner',
  admin: 'doneBodyAdmin',
  moderator: 'doneBodyModerator',
  member: 'doneBodyMember',
} as const

type PageState =
  | { phase: 'accepting' }
  | { phase: 'error'; tone: 'info' | 'error'; title: string; message: string; retry: boolean }
  | { phase: 'mismatch'; message: string; currentPublicId: string | null }
  | { phase: 'done'; accountSlug: string | null; role: AccountRole }

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
      setState({
        phase: 'error',
        tone: 'info',
        title: t('invalidTitle'),
        message: t('invalidBody'),
        retry: false,
      })
      return
    }

    acceptInvite(token)
      .then((res) => {
        setState({ phase: 'done', accountSlug: res.account_slug, role: res.role })
      })
      .catch((err: unknown) => {
        const apiErr = err as ApiError

        if (apiErr?.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(loginReturnTo)}`, { replace: true })
          return
        }

        if (apiErr?.name === 'ApiError' && apiErr.payload?.key === 'invite_mismatch') {
          // The backend's `message` is API-contract English, not localized UI
          // copy — always show our own translated string instead of leaking
          // it into an otherwise German page (it has no other consumer).
          setState({ phase: 'mismatch', message: t('mismatchBody'), currentPublicId: null })
          // Best-effort only: shows the visitor which account is actually
          // signed in right now (email is never available server-side, only
          // the opaque public_id) — helps distinguish "I'm genuinely logged
          // into the wrong account" from "this really is my email, just via
          // an alias/forward" without leaking anything sensitive.
          bootstrap()
            .then((data) => {
              if (data.user) {
                setState({
                  phase: 'mismatch',
                  message: t('mismatchBody'),
                  currentPublicId: data.user.public_id,
                })
              }
            })
            .catch(() => {})
          return
        }

        // A full team leaves the invite valid (the backend deliberately does
        // not consume it) — say so instead of the generic "expired" title.
        if (apiErr?.name === 'ApiError' && apiErr.payload?.key === 'plan_limit_team') {
          setState({
            phase: 'error',
            tone: 'info',
            title: t('teamLimitTitle'),
            message: t('teamLimitBody'),
            retry: false,
          })
          return
        }

        // The backend's single "invalid_token" key covers invalid, expired
        // and already-used links alike, so they share one honest state.
        if (apiErr?.name === 'ApiError' && apiErr.payload?.key === 'invalid_token') {
          setState({
            phase: 'error',
            tone: 'info',
            title: t('invalidTitle'),
            message: t('invalidBody'),
            retry: false,
          })
          return
        }

        // Anything else (network, 5xx) is a real failure: say so and offer a retry.
        setState({
          phase: 'error',
          tone: 'error',
          title: t('errorTitle'),
          message: t('acceptFailedFallback'),
          retry: true,
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
  // renders under GlobalLayout (no :accountSlug param yet in the URL, and
  // even if it did, that would be the CURRENT page's account, not
  // necessarily the invite's). A plain 'member' has no admin-panel access
  // at all (AuthZMiddleware accountModerate() excludes it) — send them to
  // the account's board instead of a page that would just 403 on them.
  // In self-host mode there is no /{accountSlug} URL segment at all (a
  // single tenant) — prefixing one there would 404 as an unknown board
  // slug instead, so the account-slug segment is only added in cloud mode.
  const isPlainMember = state.phase === 'done' && state.role === 'member'
  const accountPrefix =
    state.phase === 'done' && state.accountSlug !== null && getRoutingMode() === 'cloud'
      ? `/${state.accountSlug}`
      : ''
  const doneCtaPath =
    state.phase === 'done' && state.accountSlug !== null
      ? isPlainMember
        ? accountPrefix || '/'
        : `${accountPrefix}/admin/members`
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
            <Link
              to={doneCtaPath}
              data-testid="invite-done-link"
              className="ds-button ds-button--accent ds-focusable w-full"
            >
              {isPlainMember ? t('goToBoard') : t('goToMembers')}
            </Link>
          }
        >
          {t(DONE_BODY_KEY[state.role])}
        </AuthOutcome>
      )}
      {state.phase === 'mismatch' && (
        <AuthOutcome
          tone="info"
          title={t('mismatchTitle')}
          headingLevel="h1"
          action={
            <Button
              variant="accent"
              size="large"
              className="w-full"
              onClick={handleSwitchAccount}
              loading={switchingAccount}
              disabled={switchingAccount}
              testId="invite-switch-account"
            >
              {switchingAccount ? t('switchingAccount') : t('switchAccountCta')}
            </Button>
          }
        >
          {state.message}
          {state.currentPublicId !== null && (
            <p className="mt-2 text-vp-sm text-vp-text-muted">
              {t('mismatchCurrentAccount', { publicId: state.currentPublicId })}
            </p>
          )}
          <p className="mt-2 text-vp-sm text-vp-text-muted">{t('mismatchAliasHint')}</p>
        </AuthOutcome>
      )}
      {state.phase === 'error' && (
        <AuthOutcome
          tone={state.tone}
          title={state.title}
          headingLevel="h1"
          action={
            state.retry ? (
              <Button
                variant="accent"
                size="large"
                className="w-full"
                onClick={() => window.location.reload()}
                testId="invite-retry"
              >
                {t('retryCta')}
              </Button>
            ) : (
              <Link
                to="/login"
                data-testid="invite-error-login-link"
                className="ds-button ds-button--accent ds-focusable w-full"
              >
                {t('loginCta')}
              </Link>
            )
          }
        >
          {state.message}
        </AuthOutcome>
      )}
    </AuthShell>
  )
}

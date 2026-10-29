/**
 * OAuthConsentPage — /oauth/authorize?response_type=code&client_id=…
 *
 * The browser half of MCP OAuth (issue 21): an MCP client (Claude, ChatGPT,
 * …) opens this page instead of asking the user to paste a static API
 * token. The user picks ONE account they administer, the boards to share
 * and a level per board (never above what the client asked for), then
 * approves or denies. The server answers either way with `redirect_to` —
 * the client's registered redirect URI carrying the code or the OAuth
 * error — and the page leaves for it.
 *
 * Login round trip: the authorization request carries the client's
 * redirect URI (with "://"), which login's return_to validation rightly
 * refuses. So the request is parked in localStorage (PENDING_KEY, short
 * TTL) and login returns to /oauth/authorize?resume=1, which restores it.
 * The parked value is only ever replayed against the server, which
 * re-validates every parameter — it grants nothing by itself.
 *
 * Shared-origin invariant: the client name and redirect URI are chosen by
 * whoever registered the client — rendered strictly as text, never as a
 * link, image or markup.
 */

import { Button, Checkbox, ErrorState, InlineNotice, LoadingState, Select } from '@votepit/ui/ds'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError, ApiTokenScope, OAuthAuthorizeParams, OAuthConsentContext } from '../lib/api'
import { bootstrap, decideOAuthConsent, getOAuthConsent } from '../lib/api'
import { useT } from '../lib/i18n/context'

const PENDING_KEY = 'vp_oauth_pending'
const PENDING_TTL_MS = 30 * 60 * 1000
const LEVELS: ApiTokenScope[] = ['read', 'write', 'moderate']

type PageState =
  | { phase: 'loading' }
  | { phase: 'error'; message: string }
  | { phase: 'ready'; params: OAuthAuthorizeParams; context: OAuthConsentContext }
  | { phase: 'redirecting' }

function readPending(): OAuthAuthorizeParams | null {
  try {
    const raw = window.localStorage.getItem(PENDING_KEY)
    if (raw === null) return null
    const parsed = JSON.parse(raw) as { at?: unknown; params?: unknown }
    if (typeof parsed.at !== 'number' || Date.now() - parsed.at > PENDING_TTL_MS) return null
    if (typeof parsed.params !== 'object' || parsed.params === null) return null
    const params: OAuthAuthorizeParams = {}
    for (const [key, value] of Object.entries(parsed.params)) {
      if (typeof value === 'string') params[key] = value
    }
    return params
  } catch {
    return null
  }
}

function writePending(params: OAuthAuthorizeParams): void {
  try {
    window.localStorage.setItem(PENDING_KEY, JSON.stringify({ at: Date.now(), params }))
  } catch {
    // Private mode / blocked storage: the flow still works when already logged in.
  }
}

function clearPending(): void {
  try {
    window.localStorage.removeItem(PENDING_KEY)
  } catch {
    // ignore
  }
}

function leaveFor(url: string): void {
  window.location.assign(url)
}

export default function OAuthConsentPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const t = useT('oauthConsentPage')
  const [state, setState] = useState<PageState>({ phase: 'loading' })
  const [accountSlug, setAccountSlug] = useState('')
  const [grants, setGrants] = useState<Record<string, ApiTokenScope>>({})
  const [busy, setBusy] = useState<'approve' | 'deny' | null>(null)
  const [submitError, setSubmitError] = useState<string | null>(null)

  // biome-ignore lint/correctness/useExhaustiveDependencies: run once on mount; the request is fixed by the URL (or the parked copy).
  useEffect(() => {
    const params: OAuthAuthorizeParams | null =
      searchParams.get('resume') === '1' ? readPending() : Object.fromEntries(searchParams)
    if (params === null || !params.client_id) {
      setState({ phase: 'error', message: t('invalidRequest') })
      return
    }
    writePending(params)

    bootstrap()
      .then((data) => {
        if (!data.user) {
          navigate(`/login?r=${encodeURIComponent('/oauth/authorize?resume=1')}`, { replace: true })
          return
        }
        return getOAuthConsent(params).then((context) => {
          const firstEligible = context.accounts.find((account) => account.reason === null)
          setAccountSlug(firstEligible?.slug ?? '')
          if (firstEligible?.boards.length === 1) {
            setGrants({ [firstEligible.boards[0].slug]: 'read' })
          }
          setState({ phase: 'ready', params, context })
        })
      })
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        if (apiErr?.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent('/oauth/authorize?resume=1')}`, { replace: true })
          return
        }
        if (apiErr?.name === 'ApiError' && apiErr.payload?.redirect_to) {
          // A verified client + redirect URI: the error belongs to the client.
          clearPending()
          setState({ phase: 'redirecting' })
          leaveFor(apiErr.payload.redirect_to)
          return
        }
        clearPending()
        setState({ phase: 'error', message: t('invalidRequest') })
      })
  }, [])

  const context = state.phase === 'ready' ? state.context : null
  const account = useMemo(
    () => context?.accounts.find((candidate) => candidate.slug === accountSlug) ?? null,
    [context, accountSlug],
  )
  const allowedLevels = context
    ? LEVELS.slice(0, LEVELS.indexOf(context.max_scope) + 1)
    : (['read'] as ApiTokenScope[])

  const decide = async (decision: 'approve' | 'deny') => {
    if (state.phase !== 'ready' || busy !== null) return
    setBusy(decision)
    setSubmitError(null)
    try {
      const result = await decideOAuthConsent(
        state.params,
        decision === 'deny'
          ? { decision: 'deny' }
          : {
              decision: 'approve',
              account: accountSlug,
              boards: Object.entries(grants).map(([slug, scope]) => ({ slug, scope })),
            },
      )
      clearPending()
      setState({ phase: 'redirecting' })
      leaveFor(result.redirect_to)
    } catch (err: unknown) {
      const apiErr = err as ApiError
      setSubmitError(
        apiErr?.payload?.key === 'plan_limit_agent_api' ? t('planLimit') : t('submitFailed'),
      )
      setBusy(null)
    }
  }

  const selectAccount = (slug: string) => {
    setAccountSlug(slug)
    setGrants({})
  }

  const toggleBoard = (slug: string, checked: boolean) => {
    setGrants((current) => {
      const next = { ...current }
      if (checked) next[slug] = 'read'
      else delete next[slug]
      return next
    })
  }

  return (
    <AuthShell>
      {state.phase === 'loading' && <LoadingState label={t('loading')} />}
      {state.phase === 'redirecting' && (
        <AuthOutcome tone="pending">
          <span aria-busy="true">{t('redirecting')}</span>
        </AuthOutcome>
      )}
      {state.phase === 'error' && (
        <ErrorState title={t('errorTitle')} description={state.message} />
      )}
      {state.phase === 'ready' && (
        <div className="flex flex-col gap-5">
          <div className="flex flex-col gap-2">
            <h1 className="font-archivo font-bold text-vp-2xl tracking-vp-heading leading-vp-heading text-vp-title text-balance m-0">
              {t('title', { client: state.context.client.name })}
            </h1>
            <p className="text-vp-base text-vp-text-secondary leading-6">{t('intro')}</p>
            <p className="text-vp-sm text-vp-text-secondary break-all">
              {t('redirectNotice')}{' '}
              <span className="font-mono">{state.context.client.redirect_uri}</span>
            </p>
          </div>

          {state.context.accounts.every((candidate) => candidate.reason !== null) ? (
            <InlineNotice tone="warning" testId="oauth-no-account-notice">
              {state.context.accounts.length === 0 ? t('noAccounts') : t('planLimit')}
            </InlineNotice>
          ) : (
            <>
              <Select
                testId="oauth-account"
                label={t('accountLabel')}
                id="oauth-account"
                value={accountSlug}
                onChange={selectAccount}
              >
                {state.context.accounts.map((candidate) => (
                  <option
                    key={candidate.slug}
                    value={candidate.slug}
                    disabled={candidate.reason !== null}
                  >
                    {candidate.reason === null
                      ? candidate.name
                      : t('accountUnavailable', { account: candidate.name })}
                  </option>
                ))}
              </Select>

              <fieldset className="flex flex-col gap-3">
                <legend className="text-vp-sm font-medium mb-1">{t('boardsLegend')}</legend>
                {account !== null && account.boards.length === 0 && (
                  <p className="text-vp-sm text-vp-text-secondary">{t('noBoards')}</p>
                )}
                {account?.boards.map((board) => (
                  <div
                    key={board.slug}
                    className="flex flex-wrap items-center justify-between gap-3"
                  >
                    <Checkbox
                      testId={`oauth-board-${board.slug}`}
                      label={board.name}
                      checked={grants[board.slug] !== undefined}
                      onChange={(checked) => toggleBoard(board.slug, checked)}
                    />
                    {grants[board.slug] !== undefined && (
                      <Select
                        testId={`oauth-level-${board.slug}`}
                        label={t('levelLabel', { board: board.name })}
                        hideLabel
                        id={`oauth-level-${board.slug}`}
                        value={grants[board.slug]}
                        onChange={(value) =>
                          setGrants((current) => ({
                            ...current,
                            [board.slug]: value as ApiTokenScope,
                          }))
                        }
                      >
                        {allowedLevels.map((level) => (
                          <option key={level} value={level}>
                            {t(`level.${level}`)}
                          </option>
                        ))}
                      </Select>
                    )}
                  </div>
                ))}
              </fieldset>
            </>
          )}

          {submitError !== null && (
            <InlineNotice tone="error" testId="oauth-submit-error">
              {submitError}
            </InlineNotice>
          )}

          <div className="flex flex-col-reverse sm:flex-row gap-3">
            <Button
              variant="secondary"
              size="large"
              onClick={() => void decide('deny')}
              disabled={busy !== null}
              className="sm:flex-1"
              testId="oauth-deny"
            >
              {t('deny')}
            </Button>
            <Button
              variant="accent"
              size="large"
              onClick={() => void decide('approve')}
              disabled={busy !== null || accountSlug === '' || Object.keys(grants).length === 0}
              className="sm:flex-1"
              testId="oauth-approve"
            >
              {t('approve')}
            </Button>
          </div>
          <p className="text-vp-xs text-vp-text-muted">{t('revokeHint')}</p>
        </div>
      )}
    </AuthShell>
  )
}

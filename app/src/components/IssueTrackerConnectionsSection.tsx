/**
 * IssueTrackerConnectionsSection — account-level Linear/GitHub connection
 * (API tokens page, account admins). Self-contained: loads and saves via
 * GET/PUT/DELETE /admin/issue-trackers[/{provider}].
 *
 * The token is a secret: the server verifies it against the provider,
 * stores it encrypted and only ever returns a short `token_hint`, so the
 * token input is always empty ("leave empty to keep"). The GitHub webhook
 * secret is generated server-side and shown exactly once, right after the
 * first connect; Linear's signing secret is pasted by the admin.
 *
 * Plan-gated (`allowed: false`): inputs are disabled with the usual upgrade
 * hint + PlanUpgradeLink; an existing connection can still be disconnected.
 */

import { Badge, Button, CopyField, InlineNotice, Section, TextField } from '@votepit/ui/ds'
import { GitBranch } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ApiError, IssueTrackerConnection, IssueTrackerProvider } from '../lib/api'
import { connectIssueTracker, disconnectIssueTracker, getIssueTrackers } from '../lib/api'
import { useT } from '../lib/i18n/context'
import { PlanUpgradeLink } from './PlanUpgradeLink'

const PROVIDERS: IssueTrackerProvider[] = ['linear', 'github']

interface ProviderState {
  connection: IssueTrackerConnection | null
  token: string
  target: string
  secret: string
  /** GitHub: the one-time webhook secret from the last connect response. */
  revealedSecret: string | null
  busy: boolean
  error: string | null
}

const emptyState = (): ProviderState => ({
  connection: null,
  token: '',
  target: '',
  secret: '',
  revealedSecret: null,
  busy: false,
  error: null,
})

const ERROR_KEYS: Record<string, string> = {
  validation_error: 'errorValidation',
  issue_tracker_invalid_credentials: 'errorInvalidCredentials',
  issue_tracker_target_not_found: 'errorTargetNotFound',
  issue_tracker_unavailable: 'errorUnavailable',
  plan_limit_issue_tracker: 'planUpgradeHint',
}

export function IssueTrackerConnectionsSection() {
  const t = useT('issueTrackers')
  const [allowed, setAllowed] = useState(true)
  const [loadFailed, setLoadFailed] = useState(false)
  const [state, setState] = useState<Record<IssueTrackerProvider, ProviderState>>({
    linear: emptyState(),
    github: emptyState(),
  })

  const patch = (provider: IssueTrackerProvider, next: Partial<ProviderState>) =>
    setState((prev) => ({ ...prev, [provider]: { ...prev[provider], ...next } }))

  useEffect(() => {
    let cancelled = false
    getIssueTrackers()
      .then((data) => {
        if (cancelled) return
        setAllowed(data.allowed !== false)
        setState({
          linear: {
            ...emptyState(),
            connection: data.connections?.linear ?? null,
            target: data.connections?.linear?.target ?? '',
          },
          github: {
            ...emptyState(),
            connection: data.connections?.github ?? null,
            target: data.connections?.github?.target ?? '',
          },
        })
      })
      .catch(() => {
        if (!cancelled) setLoadFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const handleSave = async (provider: IssueTrackerProvider) => {
    const s = state[provider]
    if (s.busy) return
    patch(provider, { busy: true, error: null, revealedSecret: null })
    const signing = s.secret.trim()
    try {
      const body =
        provider === 'linear'
          ? {
              team_key: s.target.trim(),
              ...(s.token.trim() !== '' ? { token: s.token.trim() } : {}),
              ...(signing !== '' ? { webhook_secret: signing } : {}),
            }
          : {
              repository: s.target.trim(),
              ...(s.token.trim() !== '' ? { token: s.token.trim() } : {}),
            }
      const res = await connectIssueTracker(provider, body)
      patch(provider, {
        connection: res.connection,
        target: res.connection.target,
        token: '',
        secret: '',
        revealedSecret: res.webhook_secret ?? null,
      })
    } catch (err) {
      const apiErr = err as ApiError
      const key = apiErr.name === 'ApiError' ? apiErr.payload?.key : undefined
      if (key === 'plan_limit_issue_tracker') setAllowed(false)
      patch(provider, { error: t(ERROR_KEYS[key ?? ''] ?? 'errorGeneric') })
    } finally {
      patch(provider, { busy: false })
    }
  }

  const handleDisconnect = async (provider: IssueTrackerProvider) => {
    if (state[provider].busy) return
    patch(provider, { busy: true, error: null })
    try {
      await disconnectIssueTracker(provider)
      patch(provider, { connection: null, revealedSecret: null })
    } catch {
      patch(provider, { error: t('errorGeneric') })
    } finally {
      patch(provider, { busy: false })
    }
  }

  return (
    <Section
      testId="issue-tracker-section"
      title={t('heading')}
      icon={<GitBranch size={16} />}
      description={t('intro')}
      flush
    >
      <div className="flex flex-col gap-ds-xl px-ds-md sm:px-ds-lg py-ds-lg">
        {loadFailed && (
          <InlineNotice tone="error" testId="issue-tracker-load-error">
            {t('loadError')}
          </InlineNotice>
        )}
        {!allowed && (
          <InlineNotice tone="info" testId="issue-tracker-plan-upgrade">
            {t('planUpgradeHint')} <PlanUpgradeLink label={t('planUpgradeLinkLabel')} />
          </InlineNotice>
        )}

        {PROVIDERS.map((provider) => {
          const s = state[provider]
          const isLinear = provider === 'linear'
          const disabled = s.busy || !allowed
          const canSubmit =
            s.target.trim() !== '' && (s.connection !== null || s.token.trim() !== '')
          return (
            <form
              key={provider}
              className="flex flex-col gap-ds-sm"
              aria-label={isLinear ? t('providerLinear') : t('providerGithub')}
              onSubmit={(e) => {
                e.preventDefault()
                void handleSave(provider)
              }}
            >
              <h3 className="text-vp-base font-semibold text-vp-text flex items-center gap-ds-xs">
                {isLinear ? t('providerLinear') : t('providerGithub')}
                {s.connection !== null && (
                  <Badge tone={s.connection.sync_enabled ? 'success' : 'neutral'}>
                    {s.connection.sync_enabled ? t('syncOn') : t('syncOff')}
                  </Badge>
                )}
              </h3>

              <TextField
                className="max-w-ds-field"
                testId={`issue-tracker-${provider}-token`}
                label={isLinear ? t('linearTokenLabel') : t('githubTokenLabel')}
                name={`issue_tracker_${provider}_token`}
                id={`issue-tracker-${provider}-token`}
                type="password"
                value={s.token}
                onChange={(e) => patch(provider, { token: e.target.value })}
                hint={
                  s.connection !== null
                    ? t('tokenKeepHint', { hint: s.connection.token_hint })
                    : isLinear
                      ? t('linearTokenHint')
                      : t('githubTokenHint')
                }
                disabled={disabled}
                autoComplete="off"
                mono
              />

              <TextField
                className="max-w-ds-field"
                testId={`issue-tracker-${provider}-target`}
                label={isLinear ? t('teamKeyLabel') : t('repositoryLabel')}
                name={`issue_tracker_${provider}_target`}
                id={`issue-tracker-${provider}-target`}
                value={s.target}
                onChange={(e) => patch(provider, { target: e.target.value })}
                placeholder={isLinear ? 'ENG' : 'owner/repo'}
                hint={isLinear ? t('teamKeyHint') : t('repositoryHint')}
                disabled={disabled}
                autoComplete="off"
                mono
              />

              {isLinear && (
                <TextField
                  className="max-w-ds-field"
                  testId="issue-tracker-linear-webhook-secret"
                  label={t('webhookSecretLabel')}
                  name="issue_tracker_linear_webhook_secret"
                  id="issue-tracker-linear-webhook-secret"
                  type="password"
                  value={s.secret}
                  onChange={(e) => patch(provider, { secret: e.target.value })}
                  hint={t('webhookSecretHint')}
                  disabled={disabled}
                  autoComplete="off"
                  mono
                />
              )}

              {s.error !== null && (
                <InlineNotice tone="error" testId={`issue-tracker-${provider}-error`}>
                  {s.error}
                </InlineNotice>
              )}

              <div className="flex flex-wrap items-center gap-ds-sm">
                <Button
                  testId={`issue-tracker-${provider}-submit`}
                  type="submit"
                  variant="primary"
                  disabled={disabled || !canSubmit}
                  loading={s.busy}
                >
                  {s.connection !== null ? t('save') : t('connect')}
                </Button>
                {s.connection !== null && (
                  <Button
                    testId={`issue-tracker-${provider}-disconnect`}
                    type="button"
                    variant="secondary"
                    onClick={() => void handleDisconnect(provider)}
                    disabled={s.busy}
                  >
                    {t('disconnect')}
                  </Button>
                )}
              </div>

              {s.connection !== null && (
                <>
                  <p className="text-vp-sm text-vp-text-muted break-all">
                    {t('connectedTo', {
                      target: s.connection.target,
                      hint: s.connection.token_hint,
                    })}
                  </p>
                  <CopyField
                    testId="issue-tracker-webhook-copy"
                    label={t('webhookUrlLabel')}
                    value={s.connection.webhook_url}
                    copyLabel={t('copy')}
                    copiedLabel={t('copied')}
                    mono
                  />
                  {!isLinear && (
                    <p className="text-vp-sm text-vp-text-muted">{t('githubWebhookSetup')}</p>
                  )}
                  {s.revealedSecret !== null && (
                    <CopyField
                      testId="issue-tracker-secret-copy"
                      label={t('githubSecretOnceLabel')}
                      value={s.revealedSecret}
                      copyLabel={t('copy')}
                      copiedLabel={t('copied')}
                      mono
                    />
                  )}
                </>
              )}
            </form>
          )
        })}
      </div>
    </Section>
  )
}

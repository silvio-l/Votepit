/**
 * BoardChatNotificationsSection — Slack/Discord chat notifications for one
 * board (admin page). Self-contained: loads and saves its own settings via
 * GET/PUT/DELETE /admin/boards/{slug}/chat-notifications[/{provider}].
 *
 * The incoming-webhook URL is a secret (it embeds the webhook token): the
 * server stores it encrypted and only ever returns a short `url_hint`, so
 * the input is always empty and "replace" means pasting a new URL.
 *
 * Plan-gated (`allowed: false` on Cloud Free): inputs are disabled with the
 * usual upgrade hint + PlanUpgradeLink; an already configured integration
 * can still be disconnected (e.g. after a downgrade).
 */

import { Badge, Button, InlineNotice, Section, TextField } from '@votepit/ui/ds'
import { MessageSquare } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ApiError, ChatIntegration, ChatProvider } from '../lib/api'
import {
  deleteAdminBoardChatNotification,
  getAdminBoardChatNotifications,
  saveAdminBoardChatNotification,
} from '../lib/api'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'
import { PlanUpgradeLink } from './PlanUpgradeLink'

const PROVIDERS: ChatProvider[] = ['slack', 'discord']

const PLACEHOLDERS: Record<ChatProvider, string> = {
  slack: 'https://hooks.slack.com/services/…',
  discord: 'https://discord.com/api/webhooks/…',
}

interface ProviderState {
  integration: ChatIntegration | null
  input: string
  busy: boolean
  error: string | null
}

const emptyState = (): ProviderState => ({ integration: null, input: '', busy: false, error: null })

export function BoardChatNotificationsSection({ boardSlug }: { boardSlug: string }) {
  const t = useT('adminPage')
  const { language } = useI18n()
  const [allowed, setAllowed] = useState(true)
  const [loadFailed, setLoadFailed] = useState(false)
  const [state, setState] = useState<Record<ChatProvider, ProviderState>>({
    slack: emptyState(),
    discord: emptyState(),
  })

  const patch = (provider: ChatProvider, next: Partial<ProviderState>) =>
    setState((prev) => ({ ...prev, [provider]: { ...prev[provider], ...next } }))

  useEffect(() => {
    let cancelled = false
    getAdminBoardChatNotifications(boardSlug)
      .then((data) => {
        if (cancelled) return
        setAllowed(data.allowed !== false)
        setState({
          slack: { ...emptyState(), integration: data.integrations?.slack ?? null },
          discord: { ...emptyState(), integration: data.integrations?.discord ?? null },
        })
      })
      .catch(() => {
        if (!cancelled) setLoadFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug])

  const handleSave = async (provider: ChatProvider) => {
    const url = state[provider].input.trim()
    if (url === '' || state[provider].busy) return
    patch(provider, { busy: true, error: null })
    try {
      await saveAdminBoardChatNotification(boardSlug, provider, url)
      const fresh = await getAdminBoardChatNotifications(boardSlug)
      patch(provider, { integration: fresh.integrations?.[provider] ?? null, input: '' })
    } catch (err) {
      const apiErr = err as ApiError
      const key = apiErr.name === 'ApiError' ? apiErr.payload?.key : undefined
      if (key === 'plan_limit_chat_notifications') setAllowed(false)
      patch(provider, {
        error:
          key === 'validation_error'
            ? t('chatUrlInvalid')
            : key === 'plan_limit_chat_notifications'
              ? t('planUpgradeHint')
              : t('saveFailedGeneric'),
      })
    } finally {
      patch(provider, { busy: false })
    }
  }

  const handleRemove = async (provider: ChatProvider) => {
    if (state[provider].busy) return
    patch(provider, { busy: true, error: null })
    try {
      await deleteAdminBoardChatNotification(boardSlug, provider)
      patch(provider, { integration: null })
    } catch {
      patch(provider, { error: t('saveFailedGeneric') })
    } finally {
      patch(provider, { busy: false })
    }
  }

  return (
    <Section
      testId="chat-notifications-section"
      title={t('chatHeading')}
      icon={<MessageSquare size={16} />}
      description={t('chatIntro')}
      flush
    >
      <div className="flex flex-col gap-ds-lg px-ds-md sm:px-ds-lg py-ds-lg">
        {loadFailed && (
          <InlineNotice tone="error" testId="chat-load-error">
            {t('loadError')}
          </InlineNotice>
        )}
        {!allowed && (
          <InlineNotice tone="info" testId="chat-plan-upgrade">
            {t('planUpgradeHint')} <PlanUpgradeLink label={t('planUpgradeLinkLabel')} />
          </InlineNotice>
        )}

        {PROVIDERS.map((provider) => {
          const s = state[provider]
          const label = provider === 'slack' ? t('chatSlackLabel') : t('chatDiscordLabel')
          return (
            <form
              key={provider}
              className="flex flex-col gap-ds-sm"
              onSubmit={(e) => {
                e.preventDefault()
                void handleSave(provider)
              }}
            >
              <TextField
                className="max-w-ds-field-long"
                testId={`chat-${provider}-url`}
                label={label}
                name={`chat_${provider}_url`}
                id={`chat-${provider}-url`}
                type="url"
                value={s.input}
                onChange={(e) => patch(provider, { input: e.target.value })}
                placeholder={PLACEHOLDERS[provider]}
                mono
                error={s.error ?? undefined}
                hint={s.error !== null ? undefined : t('chatUrlHint')}
                disabled={s.busy || !allowed}
                autoComplete="off"
              />

              <div className="flex flex-wrap items-center gap-ds-sm">
                <Button
                  testId={`chat-${provider}-submit`}
                  type="submit"
                  variant="primary"
                  disabled={s.busy || !allowed || s.input.trim() === ''}
                  loading={s.busy}
                >
                  {s.integration !== null ? t('chatReplace') : t('chatConnect')}
                </Button>
                {s.integration !== null && (
                  <Button
                    testId={`chat-${provider}-disconnect`}
                    type="button"
                    variant="secondary"
                    onClick={() => void handleRemove(provider)}
                    disabled={s.busy}
                  >
                    {t('chatDisconnect')}
                  </Button>
                )}
              </div>

              {s.integration !== null && (
                <p className="flex flex-wrap items-center gap-ds-xs text-vp-sm text-vp-text-muted">
                  <span className="break-all">
                    {t('chatConnectedTo')}{' '}
                    <span className="font-mono">{s.integration.url_hint}</span>
                  </span>
                  {s.integration.last_delivery_ok === null ? (
                    <Badge tone="neutral">{t('chatNoDeliveryYet')}</Badge>
                  ) : (
                    <Badge tone={s.integration.last_delivery_ok ? 'success' : 'danger'}>
                      {s.integration.last_delivery_ok
                        ? t('webhookDeliverySuccess')
                        : t('webhookDeliveryFailed')}
                    </Badge>
                  )}
                  {s.integration.last_delivery_at !== null && (
                    <span>{formatDateTime(s.integration.last_delivery_at, language)}</span>
                  )}
                  {s.integration.last_delivery_ok === false &&
                    s.integration.last_delivery_error !== null && (
                      <span className="font-mono">{s.integration.last_delivery_error}</span>
                    )}
                </p>
              )}
            </form>
          )
        })}
      </div>
    </Section>
  )
}

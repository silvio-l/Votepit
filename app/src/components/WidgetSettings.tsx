/**
 * WidgetSettings — the "Embed widget" section of the board admin page
 * (ADR 0005 §7): allowed origins, the identify secret (rotate, shown once),
 * the install snippet, a copy-ready agent prompt and install detection.
 *
 * Self-contained (loads and saves its own data) so AdminPage only mounts it.
 * Plan-gated in the cloud: below Lite the settings stay visible read-only
 * with an upgrade link; a downgrade keeps them (the widget route 404s).
 */

import {
  Badge,
  Button,
  ConfirmDialog,
  CopyField,
  InlineNotice,
  Section,
  TextArea,
} from '@votepit/ui/ds'
import { AppWindow } from 'lucide-react'
import { useEffect, useState } from 'react'
import { accountPath } from '../lib/accountContext'
import {
  ApiError,
  type BoardWidgetSettings,
  getAdminBoardWidget,
  rotateAdminBoardWidgetSecret,
  saveAdminBoardWidgetOrigins,
} from '../lib/api'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'
import { buildWidgetAgentPrompt, buildWidgetSnippet } from '../lib/widgetPrompt'
import { PlanUpgradeLink } from './PlanUpgradeLink'

interface WidgetSettingsProps {
  boardSlug: string
}

export function WidgetSettings({ boardSlug }: WidgetSettingsProps) {
  const t = useT('widgetSettings')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [settings, setSettings] = useState<BoardWidgetSettings | null>(null)
  const [loadFailed, setLoadFailed] = useState(false)
  const [originsInput, setOriginsInput] = useState('')
  const [originsError, setOriginsError] = useState<string | undefined>(undefined)
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [rotating, setRotating] = useState(false)
  const [rotateConfirmOpen, setRotateConfirmOpen] = useState(false)
  const [revealedSecret, setRevealedSecret] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    getAdminBoardWidget(boardSlug)
      .then((data) => {
        if (cancelled) return
        setSettings(data)
        setOriginsInput(data.allowed_origins.join('\n'))
      })
      .catch(() => {
        if (!cancelled) setLoadFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug])

  if (loadFailed) {
    return (
      <Section testId="widget-settings-section" title={t('heading')} icon={<AppWindow size={16} />}>
        <InlineNotice tone="error" testId="widget-load-error">
          {t('loadFailed')}
        </InlineNotice>
      </Section>
    )
  }
  if (settings === null) return null

  const locked = !settings.plan_allowed
  const live = settings.plan_allowed && settings.embeddable && settings.allowed_origins.length > 0
  const installOptions = {
    origin: window.location.origin,
    boardPath: accountPath(`/${boardSlug}`).replace(/^\//, ''),
    audience: settings.audience,
    maxLifetime: settings.max_jwt_lifetime,
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (saving || locked) return
    setSaving(true)
    setSaved(false)
    setOriginsError(undefined)
    setGeneralError(null)
    try {
      const origins = originsInput
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '')
      const result = await saveAdminBoardWidgetOrigins(boardSlug, origins)
      setSettings({ ...settings, allowed_origins: result.allowed_origins })
      setOriginsInput(result.allowed_origins.join('\n'))
      setSaved(true)
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : null
      if (apiErr?.payload.fields?.allowed_origins !== undefined) {
        setOriginsError(t('originsInvalid', { max: settings.max_origins }))
      } else {
        setGeneralError(t('saveFailed'))
      }
    } finally {
      setSaving(false)
    }
  }

  const handleRotate = async () => {
    if (rotating || locked) return
    setRotateConfirmOpen(false)
    setRotating(true)
    setGeneralError(null)
    try {
      const result = await rotateAdminBoardWidgetSecret(boardSlug)
      setRevealedSecret(result.secret)
      setSettings({ ...settings, secret_configured: true })
    } catch {
      setGeneralError(t('rotateFailed'))
    } finally {
      setRotating(false)
    }
  }

  return (
    <Section
      testId="widget-settings-section"
      title={t('heading')}
      icon={<AppWindow size={16} />}
      description={t('intro')}
      actions={
        <Badge tone={live ? 'success' : 'neutral'}>{live ? t('statusLive') : t('statusOff')}</Badge>
      }
      flush
    >
      <div className="flex flex-col gap-ds-md px-ds-md sm:px-ds-lg py-ds-lg">
        {locked && (
          <InlineNotice tone="info" testId="widget-plan-locked">
            {t('planLocked')} <PlanUpgradeLink label={t('planUpgradeLinkLabel')} />
          </InlineNotice>
        )}
        {!settings.embeddable && (
          <InlineNotice tone="warning" testId="widget-private-board">
            {t('privateBoard')}
          </InlineNotice>
        )}

        <form id="admin-widget-form" onSubmit={handleSave} className="flex flex-col gap-ds-sm">
          <TextArea
            className="max-w-ds-field-long"
            testId="widget-allowed-origins"
            label={t('originsLabel')}
            name="widget_allowed_origins"
            id="widget-allowed-origins"
            value={originsInput}
            onChange={(e) => {
              setOriginsInput(e.target.value)
              setSaved(false)
            }}
            placeholder="https://www.example.com"
            rows={3}
            error={originsError}
            hint={
              originsError !== undefined
                ? undefined
                : t('originsHint', { max: settings.max_origins })
            }
            disabled={saving || locked}
          />
          <div className="flex flex-wrap items-center gap-ds-sm">
            <Button
              testId="widget-origins-save"
              type="submit"
              variant="primary"
              disabled={saving || locked}
              loading={saving}
            >
              {t('originsSave')}
            </Button>
            {saved && <span className="text-vp-sm text-vp-text-muted">{t('originsSaved')}</span>}
          </div>
        </form>

        <div className="flex flex-col gap-ds-sm">
          <p className="text-vp-sm font-medium">{t('secretHeading')}</p>
          <p className="text-vp-sm text-vp-text-muted">
            {settings.secret_configured ? t('secretConfigured') : t('secretMissing')}
          </p>
          {revealedSecret !== null && (
            <InlineNotice
              tone="warning"
              role="alert"
              testId="widget-secret-revealed"
              title={t('secretRevealedTitle')}
            >
              <CopyField
                testId="widget-settings-secret-copy"
                label={t('secretFieldLabel')}
                value={revealedSecret}
                copyLabel={tCommon('action.copy')}
                copiedLabel={tCommon('action.copied')}
                mono
                className="mt-ds-xs"
              />
            </InlineNotice>
          )}
          <div>
            <Button
              testId="widget-secret-rotate"
              type="button"
              variant="secondary"
              onClick={() =>
                settings.secret_configured ? setRotateConfirmOpen(true) : void handleRotate()
              }
              disabled={rotating || locked}
              loading={rotating}
            >
              {settings.secret_configured ? t('secretRotate') : t('secretGenerate')}
            </Button>
          </div>
        </div>

        {generalError !== null && (
          <InlineNotice tone="error" testId="widget-general-error">
            {generalError}
          </InlineNotice>
        )}

        <CopyField
          testId="widget-settings-snippet-copy"
          label={t('snippetLabel')}
          value={buildWidgetSnippet(installOptions)}
          copyLabel={tCommon('action.copy')}
          copiedLabel={tCommon('action.copied')}
          mono
        />
        <CopyField
          testId="widget-settings-prompt-copy"
          label={t('promptLabel')}
          value={buildWidgetAgentPrompt(installOptions)}
          copyLabel={tCommon('action.copy')}
          copiedLabel={tCommon('action.copied')}
          mono
        />

        <ConfirmDialog
          testId="widget-rotate-secret-dialog"
          open={rotateConfirmOpen}
          title={t('rotateConfirmTitle')}
          description={t('rotateConfirmBody')}
          confirmLabel={t('secretRotate')}
          cancelLabel={tCommon('action.cancel')}
          onConfirm={() => void handleRotate()}
          onCancel={() => setRotateConfirmOpen(false)}
          busy={rotating}
          tone="danger"
        />

        <p className="text-vp-sm text-vp-text-muted break-words">
          {settings.last_seen_origin !== null && settings.last_seen_at !== null
            ? t('lastSeen', {
                origin: settings.last_seen_origin,
                date: formatDateTime(settings.last_seen_at, language),
              })
            : t('notSeen')}
        </p>
      </div>
    </Section>
  )
}

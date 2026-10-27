import { InlineNotice, Section, Switch } from '@votepit/ui/ds'
import { Mail } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ApiError } from '../lib/api'
import {
  getMarketingConsent,
  type MarketingConsentStatus,
  setMarketingConsent,
} from '../lib/api/marketingConsent'
import { getFeatures } from '../lib/features'
import { useT } from '../lib/i18n/context'

/**
 * Profile toggle for product-news / promotion mail (double opt-in, migration
 * 0112). Renders nothing unless the server reports the `marketing_consent`
 * feature, so self-host never shows it. Switching on sends a confirmation
 * link to the confirmed notification e-mail; switching off withdraws at once.
 */
export function MarketingConsentSection() {
  const t = useT('marketingConsent')
  const enabled = getFeatures().marketing_consent === true
  const [status, setStatus] = useState<MarketingConsentStatus | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [loadError, setLoadError] = useState(false)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  useEffect(() => {
    if (!enabled) return
    let cancelled = false
    getMarketingConsent()
      .then((data) => {
        if (cancelled) return
        setStatus(data.status)
        setLoaded(true)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [enabled])

  if (!enabled) return null

  const handleToggle = async (next: boolean) => {
    setSaving(true)
    setSaveError(null)
    try {
      const result = await setMarketingConsent(next)
      setStatus(result.status)
    } catch (err) {
      const key = (err as ApiError)?.payload?.key
      setSaveError(
        key === 'notification_email_required' ? t('needsNotificationEmail') : t('saveError'),
      )
    } finally {
      setSaving(false)
    }
  }

  const statusText =
    status === 'pending'
      ? t('statusPending')
      : status === 'confirmed'
        ? t('statusConfirmed')
        : t('statusNone')

  return (
    <Section
      testId="profile-marketing-consent-section"
      title={t('profileHeading')}
      icon={<Mail size={16} />}
      description={t('profileHint')}
    >
      {loadError && <InlineNotice tone="error">{t('loadError')}</InlineNotice>}
      {loaded && (
        <>
          <Switch
            testId="profile-marketing-consent-toggle"
            label={t('toggleLabel')}
            hint={statusText}
            checked={status === 'confirmed' || status === 'pending'}
            onChange={(next) => void handleToggle(next)}
            disabled={saving}
          />
          {saveError !== null && (
            <InlineNotice
              tone="error"
              className="mt-ds-md"
              testId="profile-marketing-consent-error"
            >
              {saveError}
            </InlineNotice>
          )}
        </>
      )}
    </Section>
  )
}

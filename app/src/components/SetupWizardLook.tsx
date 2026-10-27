/**
 * SetupWizard "So sieht dein Board aus" (Q22 = a) — the optional look step
 * between the board step and the ready step. Accent colour, welcome text and
 * logo are saved with the board's branding endpoint; the cover image is the
 * imagery settings' job, so this step only links there.
 *
 * Plan gates come from the server's entitlements (lib/entitlements.ts via
 * lib/viewer.ts): locked options stay visible with an "Ab Lite"/"Ab Pro" badge
 * and the role-aware PlanUpgradeLink. Only the accent colour is open on Free.
 * The live preview is the same BrandingPreview the branding form uses.
 */

import { Badge, Button, cx, InlineNotice, PageHeader, TextArea, TextField } from '@votepit/ui/ds'
import { Check } from 'lucide-react'
import { type FormEvent, useEffect, useState } from 'react'
import { type ApiError, getAdminBranding, saveAdminBranding } from '../lib/api'
import type { FeatureKey } from '../lib/entitlements'
import { requiredPlanFor } from '../lib/entitlements'
import { getFeatures } from '../lib/features'
import { useT } from '../lib/i18n/context'
import {
  ACCENT_SWATCHES,
  accentHasLowContrast,
  boardSettingsTarget,
  DEFAULT_ACCENT,
  featureLocked,
  normalizeAccent,
} from '../lib/onboarding'
import { getViewerEntitlements } from '../lib/viewer'
import { useBoardAssets } from './BoardAssetsSettings'
import { BrandingPreview } from './BrandingPreview'
import { LogoField } from './LogoField'
import { PlanUpgradeLink } from './PlanUpgradeLink'
import { StepShell } from './SetupWizardStepShell'

interface SetupWizardLookProps {
  boardSlug: string
  boardName: string
  /** First run: the template's accent (already saved to the new board). Null on reopen. */
  presetAccent: string | null
  /** First run: the template's welcome text (saved on Lite and up). Null on reopen. */
  presetIntro: string | null
  /** First run: the template's typical status words, shown as a hint. */
  presetStatuses: string | null
  stepLabels: string[]
  stepsAriaLabel: string
  onSaved: () => void
  onSkip: () => void
  /** Leaves the wizard for a settings anchor (also completes onboarding). */
  onOpenSettings: (target: string) => void
}

/** Copy for a locked option: what it is, and which tier unlocks it. */
function LockedRow({
  testId,
  label,
  body,
  feature,
}: {
  testId: string
  label: string
  body: string
  feature: FeatureKey
}) {
  const t = useT('setupWizard')
  const tCommon = useT('common')
  const tier = requiredPlanFor(getViewerEntitlements(), feature)
  return (
    <div
      data-testid={testId}
      className="flex flex-col gap-2 rounded-vp-md border border-dashed border-vp-border-strong bg-vp-surface-sunken p-4"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-medium text-vp-title">{label}</span>
        <Badge tone="accent" testId={`${testId}-badge`}>
          {tier === 'pro' ? t('badgeFromPro') : t('badgeFromLite')}
        </Badge>
      </div>
      {body !== '' && <p className="m-0 text-vp-sm text-vp-text-secondary">{body}</p>}
      <p className="m-0 text-vp-xs">
        <PlanUpgradeLink label={tCommon('planUpgradeLinkLabel')} feature={feature} />
      </p>
    </div>
  )
}

export function SetupWizardLook({
  boardSlug,
  boardName,
  presetAccent,
  presetIntro,
  presetStatuses,
  stepLabels,
  stepsAriaLabel,
  onSaved,
  onSkip,
  onOpenSettings,
}: SetupWizardLookProps) {
  const t = useT('setupWizard')
  const tCommon = useT('common')
  const billing = getFeatures().billing === true
  const entitlements = getViewerEntitlements()
  const introLocked = featureLocked(entitlements, 'intro', billing)
  const logoLocked = featureLocked(entitlements, 'logo_url', billing)
  const coverLocked = featureLocked(entitlements, 'cover_upload', billing)

  const isReopen = presetAccent === null
  const [accent, setAccent] = useState(presetAccent ?? DEFAULT_ACCENT)
  const [customHex, setCustomHex] = useState('')
  const [intro, setIntro] = useState(presetIntro ?? '')
  const [logoUrl, setLogoUrl] = useState('')
  const assets = useBoardAssets(boardSlug)
  // Reopen: the board's saved look is the starting point. First run: the
  // template preset was just saved, so it already is.
  const [loaded, setLoaded] = useState(!isReopen)
  const [saving, setSaving] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)

  useEffect(() => {
    if (!isReopen) return
    let cancelled = false
    getAdminBranding(boardSlug)
      .then((branding) => {
        if (cancelled) return
        const savedAccent = normalizeAccent(branding.primary_color ?? '')
        if (savedAccent !== null) setAccent(savedAccent)
        setIntro(branding.intro ?? '')
        setLogoUrl(branding.logo_url ?? '')
      })
      .catch(() => {
        // Keep the defaults; saving still works and shows its own errors.
      })
      .finally(() => {
        if (!cancelled) setLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug, isReopen])

  const customInvalid = customHex.trim() !== '' && normalizeAccent(customHex) === null
  const inPalette = ACCENT_SWATCHES.some((swatch) => swatch.hex === accent)
  const lowContrast = accentHasLowContrast(accent)

  const handleSave = async (e: FormEvent) => {
    e.preventDefault()
    if (saving) return
    setSaving(true)
    setFieldErrors({})
    setGeneralError(null)
    // Locked fields are simply not sent: the server keeps what is stored (Q19).
    const data: Parameters<typeof saveAdminBranding>[1] = { primary_color: accent }
    if (!introLocked) data.intro = intro.trim()
    // The logo itself is uploaded on its own; this only carries a cleared legacy link.
    if (!logoLocked) data.logo_url = logoUrl.trim()
    try {
      await saveAdminBranding(boardSlug, data)
      onSaved()
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        setGeneralError(apiErr?.payload?.message ?? tCommon('state.error'))
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <StepShell
      step="look"
      labels={stepLabels}
      ariaLabel={stepsAriaLabel}
      footer={
        <div className="flex flex-1 flex-wrap items-center justify-between gap-3">
          <Button
            type="button"
            variant="text"
            onClick={onSkip}
            disabled={saving}
            className="min-h-11"
            testId="setup-wizard-look-skip"
          >
            {t('lookSkip')}
          </Button>
          <Button
            type="submit"
            form="wizard-look-form"
            variant="primary"
            disabled={saving || !loaded}
            loading={saving}
            aria-busy={saving}
            className="min-h-11"
            testId="setup-wizard-look-save"
          >
            {saving ? t('lookSaving') : t('lookSave')}
          </Button>
        </div>
      }
    >
      <PageHeader title={t('lookTitle')} description={t('lookBody')} />

      <form
        id="wizard-look-form"
        onSubmit={handleSave}
        noValidate
        className="grid grid-cols-1 gap-8 md:grid-cols-2"
        data-testid="setup-wizard-look-form"
      >
        <div className="flex min-w-0 flex-col gap-6">
          <fieldset className="m-0 flex min-w-0 flex-col gap-3 border-0 p-0">
            <legend className="mb-2 text-vp-sm font-semibold text-vp-title">
              {t('lookAccentLegend')}
            </legend>
            <div className="flex flex-wrap gap-2">
              {ACCENT_SWATCHES.map((swatch) => {
                const selected = accent === swatch.hex
                return (
                  <button
                    key={swatch.key}
                    type="button"
                    aria-pressed={selected}
                    aria-label={t(swatch.labelKey)}
                    title={t(swatch.labelKey)}
                    data-testid={`setup-wizard-look-swatch-${swatch.key}`}
                    disabled={saving}
                    onClick={() => {
                      setAccent(swatch.hex)
                      setCustomHex('')
                    }}
                    style={{ backgroundColor: swatch.hex }}
                    className={cx(
                      'inline-flex size-11 items-center justify-center rounded-vp-full border-2 transition-colors',
                      selected ? 'border-vp-ink' : 'border-transparent',
                    )}
                  >
                    {/* Check glyph on the swatch's own fixed colour (exception: not themed). */}
                    {selected && <Check size={16} aria-hidden="true" className="text-white" />}
                  </button>
                )
              })}
              {!inPalette && (
                <button
                  type="button"
                  aria-pressed="true"
                  aria-label={`${t('lookCustomLabel')} ${accent}`}
                  title={accent}
                  data-testid="setup-wizard-look-swatch-custom"
                  disabled
                  style={{ backgroundColor: accent }}
                  className="inline-flex size-11 items-center justify-center rounded-vp-full border-2 border-vp-ink"
                >
                  <Check size={16} aria-hidden="true" className="text-white" />
                </button>
              )}
            </div>
          </fieldset>

          <TextField
            label={t('lookCustomLabel')}
            name="accent_custom"
            id="wizard-look-custom-hex"
            value={customHex}
            placeholder="#205E9A"
            mono
            hint={customInvalid ? undefined : t('lookCustomHint')}
            error={customInvalid ? t('lookCustomInvalid') : fieldErrors.primary_color}
            onChange={(e) => {
              setCustomHex(e.target.value)
              const next = normalizeAccent(e.target.value)
              if (next !== null) setAccent(next)
            }}
            disabled={saving}
            testId="setup-wizard-look-custom-hex"
          />

          {lowContrast && (
            <InlineNotice tone="warning" testId="setup-wizard-look-contrast">
              {t('lookContrastWarning')}
            </InlineNotice>
          )}

          {introLocked ? (
            <LockedRow
              testId="setup-wizard-look-intro-locked"
              label={t('lookIntroLabel')}
              body={presetIntro ?? ''}
              feature="intro"
            />
          ) : (
            <TextArea
              label={t('lookIntroLabel')}
              name="intro"
              id="wizard-look-intro"
              value={intro}
              onChange={(e) => setIntro(e.target.value)}
              hint={t('lookIntroHint')}
              error={fieldErrors.intro}
              rows={3}
              maxLength={1000}
              disabled={saving}
              testId="setup-wizard-look-intro"
            />
          )}

          <LogoField
            testId="setup-wizard-look-logo"
            boardSlug={boardSlug}
            label={t('lookLogoLabel')}
            assets={assets}
            legacyUrl={logoUrl}
            onLegacyClear={() => setLogoUrl('')}
            allowed={!logoLocked}
            requiredPlan="lite"
            error={fieldErrors.logo_url}
            disabled={saving}
            maxBytes={assets.data?.max_upload_bytes_by_kind?.logo ?? 2 * 1024 * 1024}
          />

          {coverLocked ? (
            <LockedRow
              testId="setup-wizard-look-cover-locked"
              label={t('lookCoverLabel')}
              body={t('lookCoverBody')}
              feature="cover_upload"
            />
          ) : (
            <div
              data-testid="setup-wizard-look-cover"
              className="flex flex-col gap-2 rounded-vp-md border border-vp-border-subtle p-4"
            >
              <span className="font-medium text-vp-title">{t('lookCoverLabel')}</span>
              <p className="m-0 text-vp-sm text-vp-text-secondary">{t('lookCoverBody')}</p>
              <div>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => onOpenSettings(boardSettingsTarget(boardSlug, 'images'))}
                  disabled={saving}
                  className="min-h-11"
                  testId="setup-wizard-look-cover-action"
                >
                  {t('lookCoverAction')}
                </Button>
              </div>
            </div>
          )}

          {generalError !== null && (
            <InlineNotice tone="error" testId="setup-wizard-look-error">
              {generalError}
            </InlineNotice>
          )}
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          <BrandingPreview
            boardName={boardName}
            primaryColor={accent}
            secondaryColor=""
            logoUrl={logoLocked ? '' : logoUrl}
            secondaryAllowed
            compact
          />
          {presetStatuses !== null && (
            <p
              className="m-0 text-vp-xs text-vp-text-muted"
              data-testid="setup-wizard-look-statuses"
            >
              {t('lookStatusHint', { statuses: presetStatuses })}
            </p>
          )}
        </div>
      </form>
    </StepShell>
  )
}

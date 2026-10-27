import { InlineNotice, TextField } from '@votepit/ui/ds'
import { type Dispatch, type SetStateAction, useEffect, useState } from 'react'
import {
  type BoardBrandingInput,
  type BoardBrandingResponse,
  EDITABLE_WORDING_FIELDS,
  getBoardBranding,
  saveBoardBranding,
} from '../lib/api/boardBranding'
import { ApiError } from '../lib/api/client'
import type { BrandingPro, WordingField } from '../lib/branding'
import { type FeatureKey, featureEntitlement } from '../lib/entitlements'
import { LANGUAGE_NAMES, LANGUAGES, type Language, useI18n, useT } from '../lib/i18n/context'
import { PlanUpgradeLink } from './PlanUpgradeLink'

const PRESET_FEATURE: FeatureKey = 'typography_presets'
const WORDING_FEATURE: FeatureKey = 'custom_wording'
const LINKS_FEATURE: FeatureKey = 'header_links_sections'
const LINK_ROWS = 5

/** Dictionary key of each editable wording field. */
const WORDING_LABEL = {
  idea_singular: 'wordingIdeaSingular',
  idea_plural: 'wordingIdeaPlural',
  submit_button: 'wordingSubmitButton',
  empty_state: 'wordingEmptyState',
  thank_you: 'wordingThankYou',
  vote_for: 'wordingVoteFor',
  vote_against: 'wordingVoteAgainst',
} as const satisfies Partial<Record<WordingField, string>>

/** True when the plan includes the feature (unknown entitlements count as locked for the UI). */
function allowed(data: BoardBrandingResponse | null, feature: FeatureKey): boolean {
  return featureEntitlement(data?.entitlements, feature)?.allowed === true
}

function toInput(data: BoardBrandingResponse): BoardBrandingInput {
  const links = [...data.header_links]
  while (links.length < LINK_ROWS) links.push({ url: '', label: '' })
  return {
    typography_preset: data.typography_preset,
    shape_style: data.shape_style,
    density: data.density,
    wording: data.wording,
    header_links: links,
    roadmap_hidden: data.roadmap_hidden,
  }
}

export type BrandingProSave = { ok: true } | { ok: false; message: string }

/** Pro branding state of one board. The editor saves it with its other groups. */
export interface BrandingProState {
  data: BoardBrandingResponse | null
  form: BoardBrandingInput | null
  setForm: Dispatch<SetStateAction<BoardBrandingInput | null>>
  loadError: boolean
  locale: Language
  setLocale: (locale: Language) => void
  fieldErrors: Record<string, string>
  /** Which of the three Pro groups differ from the stored values. */
  dirty: { presets: boolean; wording: boolean; links: boolean }
  save: () => Promise<BrandingProSave>
  reset: () => void
}

export function useBrandingPro(boardSlug: string): BrandingProState {
  const t = useT('brandingPro')
  const { language } = useI18n()
  const [data, setData] = useState<BoardBrandingResponse | null>(null)
  const [form, setForm] = useState<BoardBrandingInput | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [locale, setLocale] = useState<Language>(language)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    if (boardSlug === '') return
    let cancelled = false
    getBoardBranding(boardSlug)
      .then((res) => {
        if (cancelled) return
        setData(res)
        setForm(toInput(res))
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug])

  const base = data === null ? null : toInput(data)
  const dirty = {
    presets:
      form !== null &&
      base !== null &&
      (form.typography_preset !== base.typography_preset ||
        form.shape_style !== base.shape_style ||
        form.density !== base.density ||
        form.roadmap_hidden !== base.roadmap_hidden),
    wording:
      form !== null &&
      base !== null &&
      JSON.stringify(form.wording) !== JSON.stringify(base.wording),
    links:
      form !== null &&
      base !== null &&
      JSON.stringify(form.header_links) !== JSON.stringify(base.header_links),
  }

  const save = async (): Promise<BrandingProSave> => {
    if (form === null) return { ok: false, message: t('saveFailed') }
    setFieldErrors({})
    try {
      await saveBoardBranding(boardSlug, form)
      const fresh = await getBoardBranding(boardSlug)
      setData(fresh)
      setForm(toInput(fresh))
      return { ok: true }
    } catch (err) {
      const payload = err instanceof ApiError ? err.payload : null
      if (payload?.key === 'plan_locked_field') return { ok: false, message: t('planLocked') }
      if (payload?.key === 'validation_error') {
        setFieldErrors(
          Object.fromEntries(
            Object.entries(payload.fields ?? {}).map(([key, message]) => [key, String(message)]),
          ),
        )
        return { ok: false, message: t('validationError') }
      }
      return { ok: false, message: t('saveFailed') }
    }
  }

  const reset = () => {
    if (data !== null) setForm(toInput(data))
    setFieldErrors({})
  }

  return { data, form, setForm, loadError, locale, setLocale, fieldErrors, dirty, save, reset }
}

const selectClass =
  'w-full rounded-vp-md border border-vp-border bg-vp-surface px-ds-sm py-ds-xs text-vp-sm text-vp-ink disabled:opacity-60'

/** Shown in place of a Pro group whose stored values did not load. */
function ProLoadError({ pro }: { pro: BrandingProState }) {
  const t = useT('brandingPro')
  if (!pro.loadError) return null
  return (
    <InlineNotice tone="error" testId="branding-pro-load-error">
      {t('loadFailed')}
    </InlineNotice>
  )
}

/** Typography, shape and density presets, plus the roadmap toggle. */
export function ProPresetFields({ pro, disabled }: { pro: BrandingProState; disabled: boolean }) {
  const t = useT('brandingPro')
  const { data, form, setForm } = pro
  if (data === null || form === null) return <ProLoadError pro={pro} />
  const presetsOn = allowed(data, PRESET_FEATURE)
  const linksOn = allowed(data, LINKS_FEATURE)

  return (
    <div className="flex flex-col gap-ds-lg">
      <fieldset
        className="flex flex-col gap-ds-sm"
        disabled={!presetsOn || disabled}
        data-testid="branding-pro-presets"
      >
        <legend className="text-vp-sm font-medium text-vp-ink">{t('presetsHeading')}</legend>
        {!presetsOn && (
          <InlineNotice tone="info" testId="branding-pro-presets-locked">
            {t('lockedHint')}{' '}
            <PlanUpgradeLink label={t('planUpgradeLinkLabel')} feature={PRESET_FEATURE} />
          </InlineNotice>
        )}
        <label className="flex flex-col gap-ds-2xs text-vp-sm text-vp-ink">
          {t('typographyLabel')}
          <select
            id="branding-pro-typography"
            data-testid="branding-pro-typography"
            className={selectClass}
            disabled={!presetsOn || disabled}
            value={form.typography_preset}
            onChange={(e) =>
              setForm({ ...form, typography_preset: e.target.value as BrandingPro['typography'] })
            }
          >
            <option value="default">{t('typoDefault')}</option>
            <option value="inter">{t('typoInter')}</option>
            <option value="mono">{t('typoMono')}</option>
          </select>
        </label>
        <label className="flex flex-col gap-ds-2xs text-vp-sm text-vp-ink">
          {t('shapeLabel')}
          <select
            id="branding-pro-shape"
            data-testid="branding-pro-shape"
            className={selectClass}
            disabled={!presetsOn || disabled}
            value={form.shape_style}
            onChange={(e) =>
              setForm({ ...form, shape_style: e.target.value as BrandingPro['shape'] })
            }
          >
            <option value="rounded">{t('shapeRounded')}</option>
            <option value="soft">{t('shapeSoft')}</option>
            <option value="square">{t('shapeSquare')}</option>
          </select>
        </label>
        <label className="flex flex-col gap-ds-2xs text-vp-sm text-vp-ink">
          {t('densityLabel')}
          <select
            id="branding-pro-density"
            data-testid="branding-pro-density"
            className={selectClass}
            disabled={!presetsOn || disabled}
            value={form.density}
            onChange={(e) =>
              setForm({ ...form, density: e.target.value as BrandingPro['density'] })
            }
          >
            <option value="comfortable">{t('densityComfortable')}</option>
            <option value="compact">{t('densityCompact')}</option>
          </select>
        </label>
      </fieldset>

      <fieldset
        className="flex flex-col gap-ds-sm"
        disabled={!linksOn || disabled}
        data-testid="branding-pro-roadmap"
      >
        {!linksOn && (
          <InlineNotice tone="info" testId="branding-pro-roadmap-locked">
            {t('lockedHint')}{' '}
            <PlanUpgradeLink label={t('planUpgradeLinkLabel')} feature={LINKS_FEATURE} />
          </InlineNotice>
        )}
        <label className="flex items-start gap-ds-xs text-vp-sm text-vp-ink">
          <input
            type="checkbox"
            id="branding-pro-roadmap-hidden"
            data-testid="branding-pro-roadmap-hidden"
            disabled={!linksOn || disabled}
            className="mt-ds-2xs"
            checked={form.roadmap_hidden}
            onChange={(e) => setForm({ ...form, roadmap_hidden: e.target.checked })}
          />
          <span>
            {t('roadmapHiddenLabel')}
            <span className="block text-vp-xs text-vp-text-muted">{t('roadmapHiddenHint')}</span>
          </span>
        </label>
      </fieldset>
    </div>
  )
}

/** Custom wording per language (Pro, custom_wording). */
export function ProWordingFields({ pro, disabled }: { pro: BrandingProState; disabled: boolean }) {
  const t = useT('brandingPro')
  const { data, form, setForm, locale, setLocale, fieldErrors } = pro
  if (data === null || form === null) return <ProLoadError pro={pro} />
  const wordingOn = allowed(data, WORDING_FEATURE)

  const setWording = (field: WordingField, value: string) =>
    setForm({
      ...form,
      wording: { ...form.wording, [locale]: { ...form.wording[locale], [field]: value } },
    })

  return (
    <fieldset
      className="flex flex-col gap-ds-sm"
      disabled={!wordingOn || disabled}
      data-testid="branding-pro-wording"
    >
      {!wordingOn && (
        <InlineNotice tone="info" testId="branding-pro-wording-locked">
          {t('lockedHint')}{' '}
          <PlanUpgradeLink label={t('planUpgradeLinkLabel')} feature={WORDING_FEATURE} />
        </InlineNotice>
      )}
      <p className="m-0 text-vp-xs text-vp-text-muted">{t('wordingHint')}</p>
      <label className="flex flex-col gap-ds-2xs text-vp-sm text-vp-ink">
        {t('wordingLocaleLabel')}
        <select
          id="branding-pro-locale"
          data-testid="branding-pro-locale"
          className={selectClass}
          disabled={!wordingOn || disabled}
          value={locale}
          onChange={(e) => setLocale(e.target.value as Language)}
        >
          {LANGUAGES.map((lang) => (
            <option key={lang} value={lang}>
              {LANGUAGE_NAMES[lang]}
            </option>
          ))}
        </select>
      </label>
      {EDITABLE_WORDING_FIELDS.map((field) => (
        <TextField
          key={`${locale}-${field}`}
          testId={`branding-pro-wording-${field}`}
          id={`branding-pro-wording-${field}`}
          label={t(WORDING_LABEL[field as keyof typeof WORDING_LABEL])}
          value={form.wording[locale]?.[field] ?? ''}
          onChange={(e) => setWording(field, e.target.value)}
          error={fieldErrors[`wording.${locale}.${field}`] ?? fieldErrors.wording}
          disabled={!wordingOn || disabled}
        />
      ))}
    </fieldset>
  )
}

/** Header links (Pro, header_links_sections): five rows, shown live in the canvas top bar. */
export function ProLinkFields({ pro, disabled }: { pro: BrandingProState; disabled: boolean }) {
  const t = useT('brandingPro')
  const { data, form, setForm, fieldErrors } = pro
  if (data === null || form === null) return <ProLoadError pro={pro} />
  const linksOn = allowed(data, LINKS_FEATURE)

  const setLink = (index: number, key: 'url' | 'label', value: string) =>
    setForm({
      ...form,
      header_links: form.header_links.map((row, i) =>
        i === index ? { ...row, [key]: value } : row,
      ),
    })

  return (
    <fieldset
      className="flex flex-col gap-ds-sm"
      disabled={!linksOn || disabled}
      data-testid="branding-pro-links"
    >
      {!linksOn && (
        <InlineNotice tone="info" testId="branding-pro-links-locked">
          {t('lockedHint')}{' '}
          <PlanUpgradeLink label={t('planUpgradeLinkLabel')} feature={LINKS_FEATURE} />
        </InlineNotice>
      )}
      <p className="m-0 text-vp-xs text-vp-text-muted">{t('linksHint')}</p>
      {form.header_links.map((row, index) => (
        // biome-ignore lint/suspicious/noArrayIndexKey: fixed five-row editor, rows never reorder
        <div key={`link-${index}`} className="grid gap-ds-sm sm:grid-cols-2">
          <TextField
            testId={`branding-pro-link-url-${index}`}
            id={`branding-pro-link-url-${index}`}
            label={t('linkUrlLabel')}
            value={row.url}
            onChange={(e) => setLink(index, 'url', e.target.value)}
            error={fieldErrors.header_links}
            disabled={!linksOn || disabled}
          />
          <TextField
            testId={`branding-pro-link-label-${index}`}
            id={`branding-pro-link-label-${index}`}
            label={t('linkLabelLabel')}
            value={row.label}
            onChange={(e) => setLink(index, 'label', e.target.value)}
            disabled={!linksOn || disabled}
          />
        </div>
      ))}
    </fieldset>
  )
}

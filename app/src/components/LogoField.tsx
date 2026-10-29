/**
 * LogoField — one board image slot as an upload (P-28b, BR-LOGO2). Two slots:
 * `logo` is the square app symbol (profile picture, browser tab); `wordmark` is the
 * wide logo for the topbar. The uploaded rendition wins; a legacy logo link is only
 * shown for the symbol (never fetched) and can be removed, which saves an empty
 * logo_url. Paid plans; Free sees the locked notice. A picked or dropped file uploads at once
 * (ImageDropzone); it is previewed while the upload runs.
 */

import { Button, InlineNotice } from '@votepit/ui/ds'
import { type ReactNode, useEffect, useMemo, useState } from 'react'
import { boardAssetUrl, deleteBoardAsset, uploadBoardAsset } from '../lib/api/boardAssets'
import { ApiError } from '../lib/api/client'
import { logoNeedsPlate } from '../lib/brandPlate'
import type { RequiredPlan } from '../lib/entitlements'
import { useT } from '../lib/i18n/context'
import type { BoardAssetsState } from './BoardAssetsSettings'
import { ImageDropzone } from './ImageDropzone'
import { PlanUpgradeLink } from './PlanUpgradeLink'
import { useUploadTermsGate } from './UploadTermsGate'

const ACCEPT = 'image/jpeg,image/png,image/webp'
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']

/** The image slot: `logo` = app symbol (square), `wordmark` = topbar logo (wide). */
type LogoSlotKind = 'logo' | 'wordmark' | 'wordmark_light'

export interface LogoFieldProps {
  testId: string
  boardSlug: string
  label: string
  /** Slot to upload into; the symbol is the default. */
  kind?: LogoSlotKind
  /** Shared assets state of the board (one fetch for the whole images group). */
  assets: BoardAssetsState
  /** Stored legacy logo link (symbol slot only); shown while no upload exists. */
  legacyUrl: string
  /** Clears the legacy link; the caller's save then writes an empty logo_url. */
  onLegacyClear: () => void
  allowed: boolean
  requiredPlan?: RequiredPlan | null
  labelEnd?: ReactNode
  disabled?: boolean
  /** Upload size limit for the slot (bytes), from the server. */
  maxBytes: number
  /** Field error from saving the board (e.g. a legacy link that may not be saved). */
  error?: string
  /** Reports the object URL of a picked, not yet uploaded file (null when none): the canvas shows it. */
  onPendingChange?: (url: string | null) => void
  /** Topbar colour the wordmark sits on. With it, a light plate is shown and explained when needed. */
  barColor?: string
}

export function LogoField({
  testId,
  boardSlug,
  label,
  kind = 'logo',
  assets,
  legacyUrl,
  onLegacyClear,
  allowed,
  requiredPlan,
  labelEnd,
  disabled = false,
  maxBytes,
  error: fieldError,
  onPendingChange,
  barColor,
}: LogoFieldProps) {
  const t = useT('boardAssets')
  const [pending, setPending] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const terms = useUploadTermsGate(boardSlug, assets, `${testId}-terms`)

  // One object URL per selected file; revoked when the selection changes or on unmount.
  const pendingUrl = useMemo(
    () => (pending !== null ? URL.createObjectURL(pending) : null),
    [pending],
  )
  useEffect(
    () => () => {
      if (pendingUrl !== null && typeof URL.revokeObjectURL === 'function')
        URL.revokeObjectURL(pendingUrl)
    },
    [pendingUrl],
  )
  useEffect(() => {
    if (onPendingChange !== undefined) onPendingChange(pendingUrl)
  }, [pendingUrl, onPendingChange])

  const stored = assets.data?.variants?.[kind] ?? null
  const storedId = stored?.id ?? null
  const storedSrc = storedId !== null ? boardAssetUrl(boardSlug, storedId) : null
  const legacy = kind === 'logo' && legacyUrl.trim() !== '' && storedId === null ? legacyUrl : null
  // A wordmark on a bar it would vanish on gets a light plate behind it (BR-LOGO2).
  const plated =
    kind === 'wordmark' &&
    storedSrc !== null &&
    barColor !== undefined &&
    logoNeedsPlate(barColor, stored?.ink ?? null)
  const hint =
    kind === 'wordmark'
      ? t('wordmarkHint')
      : kind === 'wordmark_light'
        ? t('wordmarkLightHint')
        : t('logoHint')
  const emptyText =
    kind === 'wordmark'
      ? t('wordmarkEmpty')
      : kind === 'wordmark_light'
        ? t('wordmarkLightEmpty')
        : t('logoEmpty')

  const choose = (file: File | undefined) => {
    setError(null)
    setNotice(null)
    if (file === undefined) return
    if (!ACCEPTED_TYPES.includes(file.type)) {
      setError(t('logoUnsupported'))
      return
    }
    if (file.size > maxBytes) {
      setError(t('fileTooLarge'))
      return
    }
    setPending(file)
    terms.guard(
      () => upload(file),
      () => setPending(null),
    )
  }

  const upload = async (file: File) => {
    setBusy(true)
    setError(null)
    try {
      await uploadBoardAsset(boardSlug, kind, file)
      setNotice(t('uploaded'))
      await assets.reload()
    } catch (err) {
      const key = err instanceof ApiError ? err.payload.key : ''
      setError(uploadMessage(key))
      if (key === 'upload_terms_required') await assets.reload()
    } finally {
      setPending(null)
      setBusy(false)
    }
  }

  const remove = async () => {
    setBusy(true)
    setError(null)
    try {
      await deleteBoardAsset(boardSlug, kind)
      setPending(null)
      setNotice(t('removed'))
      await assets.reload()
    } catch {
      setError(t('removeFailed'))
    } finally {
      setBusy(false)
    }
  }

  function uploadMessage(key: string): string {
    if (key === 'file_too_large') return t('fileTooLarge')
    if (key === 'unsupported_format' || key === 'invalid_upload' || key === 'invalid_image')
      return t('logoUnsupported')
    if (key === 'image_too_small') return t('logoTooSmall')
    if (key === 'image_too_large') return t('logoTooLarge')
    if (key === 'plan_feature_locked') return t('lockedImages')
    if (key === 'upload_terms_required') return t('uploadTermsRequired')
    return t('uploadFailed')
  }

  const previewSrc = pendingUrl ?? storedSrc ?? legacy
  const previewTestId =
    pendingUrl !== null ? 'pending' : storedSrc !== null ? 'preview' : 'legacy-preview'
  // Fixed white plate (exception): an uploaded logo keeps its own colours in both schemes.
  const renderPlate = (img: ReactNode) => (
    <span data-testid={`${testId}-plate`} className="inline-flex rounded-vp-md bg-white p-ds-xs">
      {img}
    </span>
  )

  return (
    <div className="flex flex-col gap-ds-sm" data-testid={testId}>
      <div className="flex items-center justify-between gap-ds-sm">
        <span className="min-w-0 text-vp-sm font-medium text-vp-title break-words">{label}</span>
        {labelEnd}
      </div>
      <span className="text-vp-xs text-vp-text-muted">{hint}</span>

      {previewSrc === null && (
        <span data-testid={`${testId}-empty`} className="text-vp-xs text-vp-text-muted">
          {emptyText}
        </span>
      )}

      {!allowed && previewSrc !== null && (
        <img
          data-testid={`${testId}-${previewTestId}`}
          src={previewSrc}
          alt={t('previewAlt')}
          className="h-16 max-w-[16rem] object-contain object-left rounded-vp-md border border-vp-border p-ds-xs"
        />
      )}

      {!allowed ? (
        <InlineNotice tone="info" testId={`${testId}-locked`}>
          {t('lockedImages')}{' '}
          <PlanUpgradeLink
            label={t('planUpgradeLinkLabel')}
            feature="logo_url"
            requiredPlan={requiredPlan ?? null}
          />
        </InlineNotice>
      ) : (
        <ImageDropzone
          testId={testId}
          label={label}
          accept={ACCEPT}
          previewSrc={previewSrc}
          previewTestId={previewTestId}
          previewAlt={pendingUrl !== null ? t('logoPendingAlt') : t('previewAlt')}
          previewClass="h-16 max-w-[16rem] object-contain"
          renderPreview={plated && pendingUrl === null ? renderPlate : undefined}
          busy={busy}
          disabled={disabled}
          onFile={choose}
          onRemove={storedId !== null ? () => void remove() : undefined}
        />
      )}

      {plated && (
        <InlineNotice tone="info" testId={`${testId}-plate-tip`}>
          {t('platedTip')}
        </InlineNotice>
      )}

      {legacy !== null && (
        <div className="flex flex-col gap-ds-xs" data-testid={`${testId}-legacy`}>
          <span className="text-vp-xs font-medium text-vp-title">{t('logoLegacyHeading')}</span>
          <span className="text-vp-xs text-vp-text-muted break-words">{legacy}</span>
          <span className="text-vp-xs text-vp-text-muted">{t('logoLegacyHint')}</span>
          <Button
            testId={`${testId}-legacy-remove`}
            type="button"
            variant="secondary"
            disabled={disabled}
            onClick={onLegacyClear}
          >
            {t('logoLegacyRemove')}
          </Button>
        </div>
      )}

      {(error !== null || fieldError !== undefined) && (
        <InlineNotice tone="error" testId={`${testId}-error`}>
          {error ?? fieldError}
        </InlineNotice>
      )}
      {notice !== null && error === null && (
        <InlineNotice tone="success" testId={`${testId}-notice`}>
          {notice}
        </InlineNotice>
      )}
      {terms.dialog}
    </div>
  )
}

import { Button, InlineNotice, TextField } from '@votepit/ui/ds'
import { type ChangeEvent, useCallback, useEffect, useMemo, useState } from 'react'
import {
  type BoardAssetKind,
  type BoardAssetsResponse,
  type BoardLinkFooterInput,
  boardAssetUrl,
  deleteBoardAsset,
  getBoardAssets,
  uploadBoardAsset,
} from '../lib/api/boardAssets'
import { ApiError } from '../lib/api/client'
import { useT } from '../lib/i18n/context'
import { PlanUpgradeLink } from './PlanUpgradeLink'
import { useUploadTermsGate } from './UploadTermsGate'

const ACCEPT = 'image/jpeg,image/png,image/webp'

/** Which stored rendition previews each slot, and which plan gate governs it. */
const SLOTS: Array<{
  kind: BoardAssetKind
  variant: 'cover_wide' | 'favicon' | 'og'
  gate: 'cover_upload' | 'favicon_og'
  heading: 'coverHeading' | 'faviconHeading' | 'ogHeading'
  hint: 'coverHint' | 'faviconHint' | 'ogHint'
  previewClass: string
}> = [
  {
    kind: 'cover',
    variant: 'cover_wide',
    gate: 'cover_upload',
    heading: 'coverHeading',
    hint: 'coverHint',
    previewClass: 'aspect-[6/1] w-full',
  },
  {
    kind: 'favicon',
    variant: 'favicon',
    gate: 'favicon_og',
    heading: 'faviconHeading',
    hint: 'faviconHint',
    previewClass: 'size-16',
  },
  {
    kind: 'og',
    variant: 'og',
    gate: 'favicon_og',
    heading: 'ogHeading',
    hint: 'ogHint',
    previewClass: 'aspect-[1200/630] w-full max-w-md',
  },
]

/** Loaded image and link state of one board; the editor owns it so the link values join its single save. */
export interface BoardAssetsState {
  data: BoardAssetsResponse | null
  loadError: boolean
  reload: () => Promise<void>
}

export function useBoardAssets(boardSlug: string): BoardAssetsState {
  const [data, setData] = useState<BoardAssetsResponse | null>(null)
  const [loadError, setLoadError] = useState(false)

  const reload = useCallback(async () => {
    if (boardSlug === '') return
    try {
      setData(await getBoardAssets(boardSlug))
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [boardSlug])

  useEffect(() => {
    void reload()
  }, [reload])

  return { data, loadError, reload }
}

/**
 * Cover, favicon and og image slots. Uploads and removals are server actions
 * that take effect at once; the editor's Verwerfen does not undo them.
 */
export function BoardImageFields({
  boardSlug,
  assets,
  disabled,
  onPendingChange,
}: {
  boardSlug: string
  assets: BoardAssetsState
  disabled: boolean
  /** Reports the object URL of a picked, not yet uploaded file (null when none): the canvas shows it. */
  onPendingChange?: (kind: BoardAssetKind, url: string | null) => void
}) {
  const t = useT('boardAssets')
  const tAdmin = useT('adminPage')
  const [pending, setPending] = useState<Partial<Record<BoardAssetKind, File>>>({})
  const [busy, setBusy] = useState<BoardAssetKind | null>(null)
  const [slotErrors, setSlotErrors] = useState<Partial<Record<BoardAssetKind, string>>>({})
  const [slotNotices, setSlotNotices] = useState<Partial<Record<BoardAssetKind, string>>>({})
  const terms = useUploadTermsGate(boardSlug, assets, 'admin-board-asset-terms')

  const pickFile = (kind: BoardAssetKind, event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    setSlotErrors((prev) => ({ ...prev, [kind]: undefined }))
    setSlotNotices((prev) => ({ ...prev, [kind]: undefined }))
    if (file !== undefined) {
      setPending((prev) => ({ ...prev, [kind]: file }))
    }
  }

  const upload = async (kind: BoardAssetKind) => {
    const file = pending[kind]
    if (file === undefined) return
    setBusy(kind)
    setSlotErrors((prev) => ({ ...prev, [kind]: undefined }))
    try {
      await uploadBoardAsset(boardSlug, kind, file)
      setPending((prev) => ({ ...prev, [kind]: undefined }))
      setSlotNotices((prev) => ({ ...prev, [kind]: t('uploaded') }))
      await assets.reload()
    } catch (err) {
      const key = err instanceof ApiError ? err.payload.key : ''
      setSlotErrors((prev) => ({ ...prev, [kind]: uploadMessage(key) }))
      if (key === 'upload_terms_required') await assets.reload()
    } finally {
      setBusy(null)
    }

    function uploadMessage(key: string): string {
      if (key === 'invalid_upload' || key === 'invalid_image') return t('invalidUpload')
      if (key === 'file_too_large') return t('fileTooLarge')
      if (key === 'plan_feature_locked') return t('lockedImages')
      if (key === 'upload_terms_required') return t('uploadTermsRequired')
      return t('uploadFailed')
    }
  }

  const remove = async (kind: BoardAssetKind) => {
    setBusy(kind)
    setSlotErrors((prev) => ({ ...prev, [kind]: undefined }))
    try {
      await deleteBoardAsset(boardSlug, kind)
      setSlotNotices((prev) => ({ ...prev, [kind]: t('removed') }))
      await assets.reload()
    } catch {
      setSlotErrors((prev) => ({ ...prev, [kind]: t('removeFailed') }))
    } finally {
      setBusy(null)
    }
  }

  // One object URL per pending file, revoked when the selection changes or the
  // component unmounts (instead of a new URL on every render).
  const pendingPreviews = useMemo(() => {
    const urls: Partial<Record<BoardAssetKind, string>> = {}
    for (const slot of SLOTS) {
      const file = pending[slot.kind]
      if (file !== undefined) urls[slot.kind] = URL.createObjectURL(file)
    }
    return urls
  }, [pending])
  useEffect(() => {
    if (onPendingChange === undefined) return
    for (const slot of SLOTS) onPendingChange(slot.kind, pendingPreviews[slot.kind] ?? null)
  }, [pendingPreviews, onPendingChange])
  useEffect(
    () => () => {
      for (const url of Object.values(pendingPreviews)) {
        if (url !== undefined && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url)
      }
    },
    [pendingPreviews],
  )

  if (assets.loadError) {
    return (
      <InlineNotice tone="error" testId="admin-board-assets-error">
        {t('uploadFailed')}
      </InlineNotice>
    )
  }
  if (assets.data === null) return null
  const data = assets.data

  return (
    <div className="flex flex-col gap-ds-lg" data-testid="admin-board-images">
      <InlineNotice tone="info" testId="admin-board-images-note">
        {tAdmin('builderImagesNote')}
      </InlineNotice>
      {SLOTS.map((slot) => {
        const locked = !data.allowed[slot.gate]
        const stored = data.variants[slot.variant]
        const pendingSrc = pendingPreviews[slot.kind]
        const previewSrc =
          pendingSrc !== undefined
            ? pendingSrc
            : stored !== undefined
              ? boardAssetUrl(boardSlug, stored.id)
              : null
        return (
          <div
            key={slot.kind}
            className="flex flex-col gap-2 max-w-ds-field"
            data-testid={`admin-board-asset-${slot.kind}`}
          >
            <span className="text-vp-sm font-medium text-vp-title">{t(slot.heading)}</span>
            <span className="text-vp-xs text-vp-text-muted">{t(slot.hint)}</span>
            {previewSrc !== null ? (
              <img
                data-testid={`admin-board-asset-${slot.kind}-preview`}
                src={previewSrc}
                alt={t('previewAlt')}
                className={`${slot.previewClass} rounded-vp-md object-cover border border-vp-border`}
              />
            ) : (
              <span
                data-testid={`admin-board-asset-${slot.kind}-empty`}
                className="text-vp-xs text-vp-text-muted"
              >
                {t('notSet')}
              </span>
            )}
            {locked ? (
              <InlineNotice tone="info" testId={`admin-board-asset-${slot.kind}-locked`}>
                {t('lockedImages')}{' '}
                <PlanUpgradeLink label={t('planUpgradeLinkLabel')} feature={slot.gate} />
              </InlineNotice>
            ) : (
              <div className="flex flex-wrap items-center gap-2">
                <input
                  id={`admin-board-asset-${slot.kind}-input`}
                  data-testid={`admin-board-asset-${slot.kind}-input`}
                  type="file"
                  accept={ACCEPT}
                  aria-label={t(slot.heading)}
                  disabled={disabled || busy !== null}
                  onChange={(e) => pickFile(slot.kind, e)}
                  className="text-vp-sm max-w-full min-w-0"
                />
                <Button
                  testId={`admin-board-asset-${slot.kind}-upload`}
                  type="button"
                  variant="secondary"
                  disabled={disabled || pending[slot.kind] === undefined || busy !== null}
                  onClick={() => terms.guard(() => upload(slot.kind))}
                >
                  {busy === slot.kind ? t('uploading') : t('upload')}
                </Button>
                {stored !== undefined && pending[slot.kind] === undefined ? (
                  <Button
                    testId={`admin-board-asset-${slot.kind}-remove`}
                    type="button"
                    variant="secondary"
                    disabled={disabled || busy !== null}
                    onClick={() => void remove(slot.kind)}
                  >
                    {t('remove')}
                  </Button>
                ) : null}
              </div>
            )}
            {slotErrors[slot.kind] !== undefined && (
              <InlineNotice tone="error" testId={`admin-board-asset-${slot.kind}-error`}>
                {slotErrors[slot.kind]}
              </InlineNotice>
            )}
            {slotNotices[slot.kind] !== undefined && (
              <InlineNotice tone="success" testId={`admin-board-asset-${slot.kind}-notice`}>
                {slotNotices[slot.kind]}
              </InlineNotice>
            )}
          </div>
        )
      })}
      {terms.dialog}
    </div>
  )
}

/** Back link and footer note. Plain fields: the editor saves them with the rest of its changes. */
export function BoardLinkFooterFields({
  value,
  onChange,
  errors,
  locked,
  disabled,
}: {
  value: BoardLinkFooterInput
  onChange: (next: BoardLinkFooterInput) => void
  errors: Partial<Record<keyof BoardLinkFooterInput, string>>
  locked: boolean
  disabled: boolean
}) {
  const t = useT('boardAssets')
  const off = locked || disabled
  return (
    <div className="flex flex-col gap-ds-md" data-testid="admin-board-link-form">
      {locked && (
        <InlineNotice tone="info" testId="admin-board-link-locked">
          {t('linkLocked')}{' '}
          <PlanUpgradeLink label={t('planUpgradeLinkLabel')} feature="back_link_footer" />
        </InlineNotice>
      )}
      <TextField
        testId="admin-board-link-url"
        id="admin-board-link-url"
        label={t('linkUrlLabel')}
        hint={t('linkUrlHint')}
        value={value.back_link_url}
        onChange={(e) => onChange({ ...value, back_link_url: e.target.value })}
        error={errors.back_link_url}
        disabled={off}
        autoComplete="off"
      />
      <TextField
        testId="admin-board-link-label"
        id="admin-board-link-label"
        label={t('linkLabelLabel')}
        hint={t('linkLabelHint')}
        value={value.back_link_label}
        onChange={(e) => onChange({ ...value, back_link_label: e.target.value })}
        error={errors.back_link_label}
        disabled={off}
        autoComplete="off"
      />
      <TextField
        testId="admin-board-footer-note"
        id="admin-board-footer-note"
        label={t('footerLabel')}
        hint={t('footerHint')}
        value={value.footer_note}
        onChange={(e) => onChange({ ...value, footer_note: e.target.value })}
        error={errors.footer_note}
        disabled={off}
        autoComplete="off"
      />
    </div>
  )
}

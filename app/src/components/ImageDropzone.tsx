/**
 * ImageDropzone — the shared upload surface of the board image slots (logo, wordmarks, cover,
 * favicon, share image). The whole area is one click target (a label around a visually hidden
 * file input, so keyboard and screen readers get a real file control) and a drop target. A file
 * picked or dropped is handed to `onFile` at once: there is no separate upload button to find.
 */

import { Button, Spinner } from '@votepit/ui/ds'
import { ImageUp } from 'lucide-react'
import { type ChangeEvent, type DragEvent, type ReactNode, useState } from 'react'
import { useT } from '../lib/i18n/context'

export interface ImageDropzoneProps {
  testId: string
  /** Accessible name of the file input (the slot's label). */
  label: string
  accept: string
  /** Image shown in the area: the picked file while it uploads, else the stored one. */
  previewSrc: string | null
  /** Test id suffix of the preview image (`preview` for a stored image, `pending` while uploading). */
  previewTestId?: string
  previewAlt: string
  /** Size classes of the preview image. */
  previewClass?: string
  /** Wraps the preview, e.g. the light plate behind a wordmark. */
  renderPreview?: (img: ReactNode) => ReactNode
  busy: boolean
  disabled: boolean
  onFile: (file: File) => void
  /** Shows a remove button below the area when given. */
  onRemove?: () => void
}

export function ImageDropzone({
  testId,
  label,
  accept,
  previewSrc,
  previewTestId = 'preview',
  previewAlt,
  previewClass = 'h-16 max-w-[16rem] object-contain',
  renderPreview,
  busy,
  disabled,
  onFile,
  onRemove,
}: ImageDropzoneProps) {
  const t = useT('boardAssets')
  const [dragging, setDragging] = useState(false)
  const inputId = `${testId}-input`
  const off = disabled || busy

  const pick = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = '' // allow picking the same file again
    if (file !== undefined) onFile(file)
  }

  const drop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault()
    setDragging(false)
    if (off) return
    const file = event.dataTransfer.files[0]
    if (file !== undefined) onFile(file)
  }

  const img =
    previewSrc !== null ? (
      <img
        data-testid={`${testId}-${previewTestId}`}
        src={previewSrc}
        alt={previewAlt}
        className={previewClass}
      />
    ) : null

  return (
    <div className="flex flex-col items-start gap-ds-xs">
      <div
        data-testid={`${testId}-dropzone`}
        role="group"
        aria-label={label}
        onDragOver={(e) => {
          e.preventDefault()
          if (!off && !dragging) setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={drop}
        className="w-full"
      >
        <label
          htmlFor={inputId}
          aria-busy={busy}
          className={`relative flex w-full flex-col items-center justify-center gap-ds-sm rounded-vp-md border-2 border-dashed p-ds-md text-center transition-colors duration-ds-fast focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-vp-accent ${
            off
              ? 'cursor-not-allowed border-vp-border opacity-60'
              : dragging
                ? 'cursor-copy border-vp-accent bg-vp-surface-muted'
                : 'cursor-pointer border-vp-border hover:border-vp-accent hover:bg-vp-surface-muted'
          }`}
        >
          <input
            id={inputId}
            data-testid={inputId}
            type="file"
            accept={accept}
            aria-label={label}
            disabled={off}
            onChange={pick}
            className="sr-only"
          />
          {img !== null ? (
            (renderPreview?.(img) ?? img)
          ) : (
            <ImageUp size={28} aria-hidden="true" className="text-vp-text-muted" />
          )}
          <span className="flex flex-col gap-ds-2xs">
            <span className="text-vp-sm font-medium text-vp-accent">
              {dragging ? t('dropRelease') : img !== null ? t('dropReplace') : t('dropChoose')}
            </span>
            {!dragging && <span className="text-vp-xs text-vp-text-muted">{t('dropOr')}</span>}
          </span>
          {busy && (
            <span
              data-testid={`${testId}-busy`}
              className="absolute inset-0 flex items-center justify-center gap-ds-xs rounded-vp-md bg-vp-surface/85 text-vp-sm font-medium text-vp-title"
            >
              <Spinner />
              {t('uploading')}
            </span>
          )}
        </label>
      </div>
      {onRemove !== undefined && (
        <Button
          testId={`${testId}-remove`}
          type="button"
          variant="text-destructive"
          disabled={off}
          onClick={onRemove}
        >
          {t('remove')}
        </Button>
      )}
    </div>
  )
}

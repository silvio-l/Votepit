import type { KeyboardEvent, ReactNode } from 'react'
import { useEffect, useId, useRef } from 'react'
import { cx } from '../lib/cx'
import { Button } from './Button'
import { Icon } from './icons'

export interface ConfirmDialogProps {
  open: boolean
  title: string
  description?: ReactNode
  confirmLabel: string
  cancelLabel: string
  /** "danger" for a destructive action (coral confirm button); "default" otherwise. */
  tone?: 'default' | 'danger'
  busy?: boolean
  onConfirm: () => void
  onCancel: () => void
  /** Stable data-testid on the dialog panel; the buttons get `<testId>-cancel` and `<testId>-confirm`. */
  testId: string
}

/**
 * The one confirmation surface: the step before a destructive action or before
 * leaving Votepit. Manual overlay (not the native dialog), so behaviour matches
 * across browsers. Focus moves to Cancel on open and returns to the opener on
 * close; Escape and a backdrop click cancel; Tab cycles the two actions.
 */
export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel,
  cancelLabel,
  tone = 'default',
  busy = false,
  onConfirm,
  onCancel,
  testId,
}: ConfirmDialogProps) {
  const panelRef = useRef<HTMLDivElement>(null)
  const restoreFocusRef = useRef<HTMLElement | null>(null)
  const titleId = useId()
  const descId = useId()

  useEffect(() => {
    if (!open) return
    restoreFocusRef.current = document.activeElement as HTMLElement | null
    panelRef.current?.querySelector<HTMLButtonElement>(`[data-testid="${testId}-cancel"]`)?.focus()
    return () => {
      restoreFocusRef.current?.focus()
    }
  }, [open, testId])

  if (!open) return null

  const handleKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key === 'Escape') {
      e.preventDefault()
      if (!busy) onCancel()
      return
    }
    if (e.key === 'Tab') {
      e.preventDefault()
      const cancel = panelRef.current?.querySelector<HTMLButtonElement>(
        `[data-testid="${testId}-cancel"]`,
      )
      const confirm = panelRef.current?.querySelector<HTMLButtonElement>(
        `[data-testid="${testId}-confirm"]`,
      )
      const next = document.activeElement === cancel ? confirm : cancel
      next?.focus()
    }
  }

  const danger = tone === 'danger'

  return (
    <div
      className="ds-confirm"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget && !busy) onCancel()
      }}
    >
      <div
        ref={panelRef}
        role="alertdialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descId : undefined}
        onKeyDown={handleKeyDown}
        data-testid={testId}
        className="ds-confirm__panel"
      >
        <div className="ds-confirm__body">
          <span
            aria-hidden="true"
            className={cx('ds-confirm__glyph', danger && 'ds-confirm__glyph--danger')}
          >
            <Icon name={danger ? 'alert' : 'info'} size="default" />
          </span>
          <div className="ds-confirm__text">
            <h2 id={titleId} className="ds-confirm__title">
              {title}
            </h2>
            {description && (
              <p id={descId} className="ds-confirm__description">
                {description}
              </p>
            )}
          </div>
        </div>
        <div className="ds-confirm__actions">
          <Button
            type="button"
            variant="secondary"
            testId={`${testId}-cancel`}
            onClick={onCancel}
            disabled={busy}
          >
            {cancelLabel}
          </Button>
          <Button
            type="button"
            variant={danger ? 'destructive' : 'primary'}
            testId={`${testId}-confirm`}
            onClick={onConfirm}
            loading={busy}
          >
            {confirmLabel}
          </Button>
        </div>
      </div>
    </div>
  )
}

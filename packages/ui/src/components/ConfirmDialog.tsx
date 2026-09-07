import type { KeyboardEvent, ReactNode } from 'react'
import { useEffect, useId, useRef } from 'react'
import { cx } from '../lib/cx'
import { Button } from './Button'

interface ConfirmDialogProps {
  open: boolean
  title: string
  description?: ReactNode
  confirmLabel: string
  cancelLabel: string
  /** "danger" for a destructive action (red confirm button); "default" otherwise. */
  tone?: 'default' | 'danger'
  busy?: boolean
  onConfirm: () => void
  onCancel: () => void
}

function DangerGlyph() {
  return (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M12 9v4M12 17h.01" />
      <path d="M10.3 3.9 2.5 17.5A2 2 0 0 0 4.2 20.5h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
    </svg>
  )
}

function QuestionGlyph() {
  return (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="9" />
      <path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01" />
    </svg>
  )
}

/**
 * The one modal confirmation surface: the step ahead of a destructive action
 * (member removal, token/board/account deletion) or ahead of leaving Votepit
 * via a user-submitted link — a dialog appearing always means "confirm
 * before this changes/removes something, or takes you somewhere else."
 * A small overlay plane (white, 2px ink edge) that scales in
 * from 96%, with a tone glyph in the head. Manual overlay (same pattern as `Menu`, not the native
 * `<dialog>`, so behaviour is identical across browsers and the test
 * environment): focus moves to Cancel on open, Escape/backdrop click cancel,
 * Tab cycles the two actions, and focus returns to whatever opened it.
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
}: ConfirmDialogProps) {
  const panelRef = useRef<HTMLDivElement>(null)
  const cancelRef = useRef<HTMLButtonElement>(null)
  const confirmRef = useRef<HTMLButtonElement>(null)
  const restoreFocusRef = useRef<HTMLElement | null>(null)
  const titleId = useId()
  const descId = useId()

  useEffect(() => {
    if (!open) return
    restoreFocusRef.current = document.activeElement as HTMLElement | null
    cancelRef.current?.focus()
    return () => {
      restoreFocusRef.current?.focus()
    }
  }, [open])

  if (!open) return null

  const handleKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key === 'Escape') {
      e.preventDefault()
      if (!busy) onCancel()
      return
    }
    if (e.key === 'Tab') {
      // Only two focusable elements — cycle between them manually to trap focus.
      e.preventDefault()
      const next = document.activeElement === cancelRef.current ? confirmRef.current : cancelRef.current
      next?.focus()
    }
  }

  const danger = tone === 'danger'

  return (
    <div
      className="fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-vp-ink/60 p-4 animate-vp-fade-in"
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
        className={cx('w-full max-w-md vp-overlay overflow-hidden animate-vp-scale-in')}
      >
        <div className="flex gap-4 px-5 pt-5 pb-4">
          <span
            aria-hidden="true"
            className={cx(
              'flex size-10 shrink-0 items-center justify-center rounded-vp-lg',
              danger
                ? 'bg-vp-vote-down-soft text-vp-vote-down-strong'
                : 'bg-vp-ink-soft text-vp-text-secondary',
            )}
          >
            {danger ? <DangerGlyph /> : <QuestionGlyph />}
          </span>
          <div className="flex flex-col gap-1.5 min-w-0 pt-1">
            <h2
              id={titleId}
              className="font-archivo font-bold text-vp-lg text-vp-ink tracking-[-0.02em] leading-6"
            >
              {title}
            </h2>
            {description && (
              <p id={descId} className="text-vp-sm text-vp-text-secondary text-pretty">
                {description}
              </p>
            )}
          </div>
        </div>
        <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 border-t border-vp-rule bg-vp-surface-frost px-5 py-3">
          <Button ref={cancelRef} type="button" variant="secondary" onClick={onCancel} disabled={busy}>
            {cancelLabel}
          </Button>
          <Button
            ref={confirmRef}
            type="button"
            variant={danger ? 'danger' : 'primary'}
            onClick={onConfirm}
            disabled={busy}
            loading={busy}
            aria-busy={busy}
          >
            {confirmLabel}
          </Button>
        </div>
      </div>
    </div>
  )
}

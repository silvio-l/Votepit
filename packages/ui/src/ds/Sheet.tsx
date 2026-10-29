import { type ReactNode, useEffect, useId, useRef } from 'react'

export interface SheetProps {
  open: boolean
  /** Called on Escape, on a tap on the scrim and on the close button. */
  onClose: () => void
  /** Dialog heading (also its accessible name). */
  title: string
  /** Accessible name of the close button, e.g. "Close". */
  closeLabel: string
  children: ReactNode
  /** Stable data-testid on the sheet root. */
  testId: string
  /** Id of the element that describes the dialog (aria-describedby on the dialog panel). */
  describedBy?: string
}

const FOCUSABLE =
  'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'

/**
 * Bottom sheet on phones, centred dialog from 640 px up. While open it is a
 * modal dialog: focus moves into the panel, Tab cycles inside it, Escape or a
 * scrim tap closes it, body scroll is locked, and focus returns to the
 * element that opened it. Renders nothing while closed.
 */
export function Sheet({
  open,
  onClose,
  title,
  closeLabel,
  children,
  testId,
  describedBy,
}: SheetProps) {
  const panelRef = useRef<HTMLDivElement>(null)
  const titleId = useId()
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!open) return
    const panel = panelRef.current
    const opener = document.activeElement as HTMLElement | null
    const bodyOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    ;(panel?.querySelector<HTMLElement>(FOCUSABLE) ?? panel)?.focus()

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onCloseRef.current()
        return
      }
      if (event.key !== 'Tab' || !panel) return
      const items = [...panel.querySelectorAll<HTMLElement>(FOCUSABLE)]
      if (items.length === 0) {
        event.preventDefault()
        panel.focus()
        return
      }
      const first = items[0]
      const last = items[items.length - 1]
      const active = document.activeElement
      if (event.shiftKey && (active === first || active === panel)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && active === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = bodyOverflow
      opener?.focus?.()
    }
  }, [open])

  if (!open) return null

  return (
    <div className="ds-sheet" data-testid={testId}>
      <div className="ds-sheet__scrim" aria-hidden="true" onClick={() => onCloseRef.current()} />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={describedBy}
        tabIndex={-1}
        className="ds-sheet__panel"
      >
        <div className="ds-sheet__grip" aria-hidden="true" />
        <div className="ds-sheet__head">
          <h2 id={titleId} className="ds-sheet__title">
            {title}
          </h2>
          <button
            type="button"
            className="ds-sheet__close"
            aria-label={closeLabel}
            data-testid={`${testId}-close`}
            onClick={() => onCloseRef.current()}
          >
            <span aria-hidden="true">×</span>
          </button>
        </div>
        <div className="ds-sheet__body">{children}</div>
      </div>
    </div>
  )
}

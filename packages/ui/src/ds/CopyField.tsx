import type { ReactNode } from 'react'
import { useEffect, useId, useRef, useState } from 'react'
import { cx } from '../lib/cx'
import { Button } from './Button'

export interface CopyFieldProps {
  /** Small label above the value (a public link, a one-time token). */
  label: string
  value: string
  copyLabel: string
  /** Shown on the button and announced once the copy succeeded. */
  copiedLabel: string
  /** Monospace value (URLs, tokens, ids). */
  mono?: boolean
  /** Decorative glyph before the label. */
  icon?: ReactNode
  /** Extra controls next to the copy button (e.g. a QR toggle). */
  actions?: ReactNode
  className?: string
  /** Stable data-testid on the copy button (kebab-case, `<screen>-<element>`). */
  testId: string
}

/** How long the "copied" confirmation stays on the button. */
const COPIED_RESET_MS = 2000

/**
 * A read-only value with a copy button: the way the app hands the user something to paste
 * elsewhere. Uses the async clipboard API; if that is unavailable or denied, it selects the
 * value so a manual copy works. The copied state is announced politely.
 */
export function CopyField({
  label,
  value,
  copyLabel,
  copiedLabel,
  mono = false,
  icon,
  actions,
  className,
  testId,
}: CopyFieldProps) {
  const labelId = useId()
  const valueRef = useRef<HTMLElement>(null)
  const timerRef = useRef<number | null>(null)
  const [copied, setCopied] = useState(false)

  useEffect(
    () => () => {
      if (timerRef.current !== null) window.clearTimeout(timerRef.current)
    },
    [],
  )

  const markCopied = () => {
    setCopied(true)
    if (timerRef.current !== null) window.clearTimeout(timerRef.current)
    timerRef.current = window.setTimeout(() => setCopied(false), COPIED_RESET_MS)
  }

  const selectValue = (): boolean => {
    const node = valueRef.current
    const selection = window.getSelection()
    if (!node || !selection) return false
    const range = document.createRange()
    range.selectNodeContents(node)
    selection.removeAllRanges()
    selection.addRange(range)
    return true
  }

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(value)
      markCopied()
      return
    } catch {
      // Clipboard API unavailable or denied: fall through to the selection path.
    }
    if (!selectValue()) return
    try {
      if (document.execCommand('copy')) markCopied()
    } catch {
      // The text stays selected, so Ctrl/Cmd+C still works.
    }
  }

  return (
    <div className={cx('ds-copy-field', className)}>
      <span id={labelId} className="ds-copy-field__label">
        {icon && (
          <span aria-hidden="true" className="ds-copy-field__icon">
            {icon}
          </span>
        )}
        {label}
      </span>
      <div role="group" aria-labelledby={labelId} className="ds-copy-field__box">
        <code
          ref={valueRef}
          className={cx('ds-copy-field__value', mono && 'ds-copy-field__value--mono')}
        >
          {value}
        </code>
        <div className="ds-copy-field__controls">
          {actions}
          <Button variant="secondary" testId={testId} onClick={() => void handleCopy()}>
            {copied ? copiedLabel : copyLabel}
          </Button>
        </div>
      </div>
      <span role="status" aria-live="polite" className="ds-visually-hidden">
        {copied ? copiedLabel : ''}
      </span>
    </div>
  )
}

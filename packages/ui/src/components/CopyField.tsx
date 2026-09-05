import type { ReactNode } from 'react'
import { useEffect, useId, useRef, useState } from 'react'
import { cx } from '../lib/cx'
import { Button } from './Button'

interface CopyFieldProps {
  /** Small label above the value (a public link, a one-time token). */
  label: string
  value: string
  copyLabel: string
  /** Shown on the button and announced to AT once the copy succeeded. */
  copiedLabel: string
  /** Monospace value (URLs, tokens, ids). */
  mono?: boolean
  /** Optional glyph before the label. Decorative. */
  icon?: ReactNode
  className?: string
}

/** How long the "copied" confirmation stays on the button. */
const COPIED_RESET_MS = 2000

export function CopyGlyph() {
  return (
    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <rect x="5.5" y="5.5" width="8" height="8" rx="1.5" />
      <path d="M10.5 5.5v-2a1 1 0 0 0-1-1h-6a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2" />
    </svg>
  )
}

export function CheckGlyph() {
  return (
    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M3.5 8.5l3 3 6-7" />
    </svg>
  )
}

/**
 * A read-only value with a copy button — the one way the app hands the user
 * something to paste elsewhere (a board link, an API token). Copies through
 * the async clipboard API; where that is unavailable or denied it selects
 * the text so a manual copy works, and tries the legacy copy command on that
 * selection. The copied state is announced through a polite live region.
 */
export function CopyField({
  label,
  value,
  copyLabel,
  copiedLabel,
  mono = false,
  icon,
  className,
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
      // Clipboard API unavailable or denied — fall through to the selection path.
    }
    if (!selectValue()) return
    try {
      if (document.execCommand('copy')) markCopied()
    } catch {
      // The text stays selected, so Ctrl/Cmd+C still works.
    }
  }

  return (
    <div className={cx('flex flex-col gap-1.5 min-w-0', className)}>
      <span id={labelId} className="vp-eyebrow inline-flex items-center gap-1.5">
        {icon && (
          <span aria-hidden="true" className="inline-flex shrink-0">
            {icon}
          </span>
        )}
        {label}
      </span>
      <div
        role="group"
        aria-labelledby={labelId}
        className="flex items-center gap-2 pl-3 pr-1.5 py-1.5 bg-vp-surface-sunken border border-vp-border-subtle rounded-vp-md"
      >
        <code
          ref={valueRef}
          className={cx(
            'flex-1 min-w-0 text-vp-sm text-vp-ink break-all select-all',
            mono && 'font-mono-num',
          )}
        >
          {value}
        </code>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          icon={copied ? <CheckGlyph /> : <CopyGlyph />}
          onClick={() => void handleCopy()}
          className="shrink-0"
        >
          {copied ? copiedLabel : copyLabel}
        </Button>
      </div>
      <span role="status" aria-live="polite" className="sr-only">
        {copied ? copiedLabel : ''}
      </span>
    </div>
  )
}

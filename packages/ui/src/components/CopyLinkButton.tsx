import { useEffect, useId, useRef, useState } from 'react'
import { IconButton, type ButtonSize, type ButtonVariant } from './Button'
import { CheckGlyph, CopyGlyph } from './CopyField'

interface CopyLinkButtonProps {
  value: string
  label: string
  copiedLabel: string
  variant?: Exclude<ButtonVariant, 'link'>
  size?: ButtonSize
  className?: string
}

/** How long the "copied" confirmation stays on the button. */
const COPIED_RESET_MS = 2000

/**
 * Icon-only button that copies `value` to the clipboard — the compact
 * counterpart to CopyField for places (list rows) where showing the full
 * value would not fit. Copies through the async clipboard API; unlike
 * CopyField there is no visible value on the page to fall back to selecting
 * when that API is unavailable or denied.
 */
export function CopyLinkButton({
  value,
  label,
  copiedLabel,
  variant = 'ghost',
  size = 'sm',
  className,
}: CopyLinkButtonProps) {
  const timerRef = useRef<number | null>(null)
  const [copied, setCopied] = useState(false)
  const statusId = useId()

  useEffect(
    () => () => {
      if (timerRef.current !== null) window.clearTimeout(timerRef.current)
    },
    [],
  )

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      if (timerRef.current !== null) window.clearTimeout(timerRef.current)
      timerRef.current = window.setTimeout(() => setCopied(false), COPIED_RESET_MS)
    } catch {
      // Clipboard API unavailable or denied — nothing to fall back to.
    }
  }

  return (
    <>
      <IconButton
        variant={variant}
        size={size}
        label={copied ? copiedLabel : label}
        aria-describedby={statusId}
        onClick={() => void handleCopy()}
        className={className}
      >
        {copied ? <CheckGlyph /> : <CopyGlyph />}
      </IconButton>
      <span id={statusId} role="status" aria-live="polite" className="sr-only">
        {copied ? copiedLabel : ''}
      </span>
    </>
  )
}

import { useEffect, useId, useRef, useState } from 'react'
import { Button, IconButton } from './Button'

const COPIED_RESET_MS = 2000

export interface CopyLinkButtonProps {
  value: string
  label: string
  copiedLabel: string
  className?: string
  /** Show the label as visible text next to the icon (action rows). Default: icon only. */
  showLabel?: boolean
  /** Stable data-testid on the button. */
  testId: string
}

/**
 * Copies a link through the async clipboard API and announces the result.
 * No visible fallback: when the API is denied there is nothing to select.
 */
export function CopyLinkButton({
  value,
  label,
  copiedLabel,
  className,
  showLabel = false,
  testId,
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
      // Clipboard API unavailable or denied: nothing to fall back to.
    }
  }

  if (showLabel) {
    return (
      <>
        <Button
          variant="secondary"
          icon={copied ? 'check' : 'copy'}
          testId={testId}
          aria-describedby={statusId}
          onClick={() => void handleCopy()}
          className={className}
        >
          {copied ? copiedLabel : label}
        </Button>
        <span id={statusId} role="status" aria-live="polite" className="ds-visually-hidden">
          {copied ? copiedLabel : ''}
        </span>
      </>
    )
  }

  return (
    <>
      <IconButton
        icon={copied ? 'check' : 'copy'}
        accessibleLabel={copied ? copiedLabel : label}
        testId={testId}
        aria-describedby={statusId}
        onClick={() => void handleCopy()}
        className={className}
      />
      <span id={statusId} role="status" aria-live="polite" className="ds-visually-hidden">
        {copied ? copiedLabel : ''}
      </span>
    </>
  )
}

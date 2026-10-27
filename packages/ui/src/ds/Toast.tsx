import type { ReactNode } from 'react'
import { useCallback, useEffect, useState } from 'react'
import { cx } from '../lib/cx'
import { prefersReducedMotion } from './motion'
import { tokens } from './tokens.generated'

export type ToastType = 'success' | 'error' | 'info' | 'warning'

/** Exit length: the DS fast motion token (same value as --vpds-motion-fast), so JS and CSS cannot drift. */
const EXIT_MS = Number.parseInt(tokens['semantic.motion.fast'].value, 10)

export interface ToastProps {
  message: string
  /** Optional second line. */
  description?: string
  type?: ToastType
  onClose?: () => void
  /** Optional action rendered after the text (an undo button, a link). */
  action?: ReactNode
  /** Auto-dismiss delay in ms; 0 disables. Defaults to 5000. */
  duration?: number
  /** Accessible name of the close button, localized by the app. */
  closeAriaLabel: string
  /** Stable test id (data-testid); the close button gets `<testId>-close`. */
  testId: string
}

const PATHS: Record<ToastType, ReactNode> = {
  success: <path d="M3.5 8.5l3 3 6-7" />,
  error: <path d="M4.5 4.5l7 7M11.5 4.5l-7 7" />,
  info: <path d="M8 7v5M8 4.5v.5" />,
  warning: <path d="M8 4v5M8 11.5v.5" />,
}

/**
 * Transient page-level confirmation: a white plane with a 2px ink edge, a tinted
 * glyph and a thin progress bar that drains over the auto-dismiss window (pauses
 * while hovered). Bottom-centre on phones, bottom-right on larger screens.
 */
export function Toast({
  message,
  description,
  type = 'info',
  onClose,
  action,
  duration = 5000,
  closeAriaLabel,
  testId,
}: ToastProps) {
  const [paused, setPaused] = useState(false)
  const [leaving, setLeaving] = useState(false)

  // Dismissal plays the exit first and calls onClose once it has finished, so the owner
  // unmounts a toast that has already left. Reduced motion closes at once, no exit.
  const dismiss = useCallback(() => {
    if (!onClose) return
    if (prefersReducedMotion(globalThis.window)) onClose()
    else setLeaving(true)
  }, [onClose])

  useEffect(() => {
    if (!leaving || !onClose) return
    const timer = setTimeout(onClose, EXIT_MS)
    return () => clearTimeout(timer)
  }, [leaving, onClose])

  useEffect(() => {
    if (!onClose || duration <= 0 || paused || leaving) return
    const timer = setTimeout(dismiss, duration)
    return () => clearTimeout(timer)
  }, [onClose, dismiss, duration, paused, leaving])

  return (
    <div
      className={cx('ds-toast', leaving && 'ds-toast--leaving')}
      data-testid={testId}
      role={type === 'error' ? 'alert' : 'status'}
      aria-live={type === 'error' ? 'assertive' : 'polite'}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      <div className={cx('ds-toast__panel', `ds-toast__panel--${type}`)}>
        <span aria-hidden="true" className="ds-toast__glyph">
          <svg
            viewBox="0 0 16 16"
            width="14"
            height="14"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            {PATHS[type]}
          </svg>
        </span>
        <div className="ds-toast__body">
          <p className="ds-toast__message">{message}</p>
          {description && <p className="ds-toast__description">{description}</p>}
          {action && <div className="ds-toast__action">{action}</div>}
        </div>
        {onClose && (
          <button
            type="button"
            onClick={dismiss}
            className="ds-toast__close"
            data-testid={`${testId}-close`}
            aria-label={closeAriaLabel}
            title={closeAriaLabel}
          >
            <svg
              viewBox="0 0 16 16"
              width="14"
              height="14"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.75"
              strokeLinecap="round"
              aria-hidden="true"
            >
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        )}
        {onClose && duration > 0 && (
          <span
            aria-hidden="true"
            className={cx('ds-toast__progress', `ds-toast__progress--${type}`)}
            style={{
              animationDuration: `${duration}ms`,
              animationPlayState: paused ? 'paused' : 'running',
            }}
          />
        )}
      </div>
    </div>
  )
}

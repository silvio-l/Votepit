import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { cx } from '../lib/cx'

export type ToastType = 'success' | 'error' | 'info' | 'warning'

interface ToastProps {
  message: string
  /** Optional second line. */
  description?: string
  type?: ToastType
  onClose?: () => void
  /** Optional action rendered after the text (an undo button, a link). */
  action?: ReactNode
  /** Auto-dismiss delay in ms; 0 disables. Defaults to 5000. */
  duration?: number
  /** i18n override — defaults to English. */
  closeAriaLabel?: string
}

const accentClasses: Record<ToastType, string> = {
  success: 'text-vp-vote-up-strong',
  error: 'text-vp-vote-down-strong',
  info: 'text-vp-info-strong',
  warning: 'text-vp-warn-strong',
}

const barClasses: Record<ToastType, string> = {
  success: 'bg-vp-vote-up',
  error: 'bg-vp-vote-down',
  info: 'bg-vp-info',
  warning: 'bg-vp-warn',
}

function Glyph({ type }: { type: ToastType }) {
  const paths: Record<ToastType, ReactNode> = {
    success: <path d="M3.5 8.5l3 3 6-7" />,
    error: <path d="M4.5 4.5l7 7M11.5 4.5l-7 7" />,
    info: <path d="M8 7v5M8 4.5v.5" />,
    warning: <path d="M8 4v5M8 11.5v.5" />,
  }
  return (
    <span
      aria-hidden="true"
      className={cx(
        'flex size-6 shrink-0 items-center justify-center rounded-vp-full animate-vp-stamp',
        accentClasses[type],
        type === 'success' && 'bg-vp-vote-up-soft',
        type === 'error' && 'bg-vp-vote-down-soft',
        type === 'info' && 'bg-vp-info-soft',
        type === 'warning' && 'bg-vp-warn-soft',
      )}
    >
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
        {paths[type]}
      </svg>
    </span>
  )
}

/**
 * Transient page-level confirmation. Paper white, lifted, a coloured glyph
 * and a thin progress bar that drains over the auto-dismiss window (pauses
 * while hovered). Bottom-centre on phones, bottom-right on larger screens;
 * escapes every container.
 */
export function Toast({
  message,
  description,
  type = 'info',
  onClose,
  action,
  duration = 5000,
  closeAriaLabel = 'Close notification',
}: ToastProps) {
  const [paused, setPaused] = useState(false)

  useEffect(() => {
    if (!onClose || duration <= 0 || paused) return
    const timer = setTimeout(onClose, duration)
    return () => clearTimeout(timer)
  }, [onClose, duration, paused])

  return (
    <div
      className="fixed bottom-4 left-1/2 -translate-x-1/2 sm:left-auto sm:right-5 sm:bottom-5 sm:translate-x-0 z-[100] w-[calc(100vw-2rem)] sm:w-auto sm:max-w-sm"
      role={type === 'error' ? 'alert' : 'status'}
      aria-live={type === 'error' ? 'assertive' : 'polite'}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      <div
        className={cx(
          'relative overflow-hidden flex items-start gap-3 rounded-vp-lg pl-4 pr-2 py-3',
          'vp-overlay text-vp-ink animate-vp-rise',
        )}
      >
        <Glyph type={type} />
        <div className="min-w-0 flex-1 py-0.5">
          <p className="text-vp-base font-medium leading-5">{message}</p>
          {description && (
            <p className="mt-0.5 text-vp-sm text-vp-text-secondary leading-5">{description}</p>
          )}
          {action && <div className="mt-2">{action}</div>}
        </div>
        {onClose && (
          <button
            type="button"
            onClick={onClose}
            className="flex size-8 shrink-0 items-center justify-center rounded-vp-sm text-vp-text-muted hover:text-vp-ink hover:bg-vp-ink-soft transition-colors cursor-pointer"
            aria-label={closeAriaLabel}
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
            className={cx(
              'absolute bottom-0 left-0 h-0.5 w-full origin-left animate-vp-progress',
              barClasses[type],
            )}
            style={{ animationDuration: `${duration}ms`, animationPlayState: paused ? 'paused' : 'running' }}
          />
        )}
      </div>
    </div>
  )
}

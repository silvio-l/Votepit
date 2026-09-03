import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export type AlertTone = 'info' | 'success' | 'warning' | 'error'

interface AlertProps {
  tone?: AlertTone
  title?: string
  children?: ReactNode
  /** Optional trailing action (e.g. a retry or dismiss button). */
  action?: ReactNode
  /**
   * ARIA live role. Defaults to "alert" for errors and "status" otherwise —
   * pass explicitly when a success message must interrupt (e.g. a revealed
   * one-time secret) or an error must not.
   */
  role?: 'alert' | 'status' | 'none'
  className?: string
}

const toneClasses: Record<AlertTone, string> = {
  info: 'bg-vp-info-soft/70 border-vp-info/30 text-vp-ink',
  success: 'bg-vp-vote-up-soft/70 border-vp-vote-up/30 text-vp-ink',
  warning: 'bg-vp-warn-soft/70 border-vp-warn/30 text-vp-ink',
  error: 'bg-vp-vote-down-soft/70 border-vp-vote-down/30 text-vp-ink',
}

const glyphClasses: Record<AlertTone, string> = {
  info: 'text-vp-info-strong bg-vp-info-soft',
  success: 'text-vp-vote-up-strong bg-vp-vote-up-soft',
  warning: 'text-vp-warn-strong bg-vp-warn-soft',
  error: 'text-vp-vote-down-strong bg-vp-vote-down-soft',
}

function Glyph({ tone }: { tone: AlertTone }) {
  // These 4 tone glyphs stay hand-drawn at the same 1.75-stroke weight as
  // the ballot marks and checkbox check, so a success/error alert reads as
  // part of that product-specific mark system rather than a generic icon.
  const paths: Record<AlertTone, string> = {
    info: 'M8 7v5M8 4.5v.5',
    success: 'M3.5 8.5l3 3 6-7',
    warning: 'M8 4v5M8 11.5v.5',
    error: 'M4.5 4.5l7 7M11.5 4.5l-7 7',
  }
  return (
    <span
      aria-hidden="true"
      className={cx(
        'flex size-6 shrink-0 items-center justify-center rounded-vp-full',
        glyphClasses[tone],
      )}
    >
      <svg
        viewBox="0 0 16 16"
        width="14"
        height="14"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.9"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d={paths[tone]} />
      </svg>
    </span>
  )
}

/**
 * Inline feedback block — the single vocabulary for success / error / info /
 * warning messages inside a page (toasts are for transient, page-level
 * confirmations). Text-safe colours on tinted paper, 1px rule, a round glyph.
 */
export function Alert({ tone = 'info', title, children, action, role, className }: AlertProps) {
  const liveRole = role ?? (tone === 'error' ? 'alert' : 'status')
  return (
    <div
      role={liveRole === 'none' ? undefined : liveRole}
      className={cx(
        'flex items-start gap-3 rounded-vp-md border px-3.5 py-3 text-vp-sm animate-vp-fade-in',
        toneClasses[tone],
        className,
      )}
    >
      <Glyph tone={tone} />
      <div className="min-w-0 flex-1 leading-5 py-0.5">
        {title && <p className="font-semibold text-vp-base">{title}</p>}
        {children && <div className={cx(title && 'mt-0.5 text-vp-text-secondary')}>{children}</div>}
      </div>
      {action && <div className="shrink-0 -my-1">{action}</div>}
    </div>
  )
}

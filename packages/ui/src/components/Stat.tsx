import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

interface StatProps {
  label: string
  value: ReactNode
  tone?: 'default' | 'up' | 'down'
  className?: string
}

/**
 * One declared figure: mono value above a plain label. Renders a dt/dd pair,
 * so wrap a row of Stats in a <dl>. DOM order is label→value (valid dl), the
 * visual order is value→label.
 */
export function Stat({ label, value, tone = 'default', className }: StatProps) {
  return (
    <div className={cx('flex flex-col gap-0.5 min-w-0', className)}>
      <dt className="order-2 text-vp-xs text-vp-text-secondary leading-4">{label}</dt>
      <dd
        className={cx(
          'order-1 font-mono-num font-bold text-vp-xl leading-7 tracking-tight',
          tone === 'up' && 'text-vp-vote-up-strong',
          tone === 'down' && 'text-vp-vote-down-strong',
          tone === 'default' && 'text-vp-ink',
        )}
      >
        {value}
      </dd>
    </div>
  )
}

interface StatCardProps {
  label: string
  value: ReactNode
  /** Small caption under the value ("of 500", "last 7 days"). */
  caption?: ReactNode
  /** 16px glyph in the head. */
  icon?: ReactNode
  /** Trend marker: sign decides colour, text is shown verbatim ("+12%"). */
  delta?: { value: string; direction: 'up' | 'down' | 'flat' }
  /** 0–100 usage bar under the value (quota tiles). */
  progress?: number
  tone?: 'default' | 'accent' | 'warning' | 'danger'
  href?: string
  className?: string
}

/**
 * A dashboard tile: label, one big mono figure, an optional trend or usage
 * bar. Wrap several in a grid at the head of an overview page.
 */
export function StatCard({
  label,
  value,
  caption,
  icon,
  delta,
  progress,
  tone = 'default',
  href,
  className,
}: StatCardProps) {
  const Tag = href ? 'a' : 'div'
  const clamped = progress === undefined ? undefined : Math.max(0, Math.min(100, progress))
  return (
    <Tag
      href={href}
      className={cx(
        'vp-card flex flex-col gap-3 p-4 sm:p-5 no-underline text-inherit',
        href && 'vp-card--interactive',
        className,
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="text-vp-sm font-medium text-vp-text-secondary truncate">{label}</span>
        {icon && (
          <span
            aria-hidden="true"
            className={cx(
              'inline-flex size-7 shrink-0 items-center justify-center rounded-vp-md',
              tone === 'accent' && 'bg-vp-accent-soft text-vp-accent-strong',
              tone === 'warning' && 'bg-vp-warn-soft text-vp-warn-strong',
              tone === 'danger' && 'bg-vp-vote-down-soft text-vp-vote-down-strong',
              tone === 'default' && 'bg-vp-bg text-vp-text-muted',
            )}
          >
            {icon}
          </span>
        )}
      </div>
      <div className="flex items-baseline gap-2 flex-wrap">
        <span className="font-mono-num font-bold text-vp-3xl leading-8 tracking-tight text-vp-ink">
          {value}
        </span>
        {delta && (
          <span
            className={cx(
              'inline-flex items-center gap-0.5 text-vp-xs font-medium font-mono-num',
              delta.direction === 'up' && 'text-vp-vote-up-strong',
              delta.direction === 'down' && 'text-vp-vote-down-strong',
              delta.direction === 'flat' && 'text-vp-text-muted',
            )}
          >
            {delta.direction !== 'flat' && (
              <svg viewBox="0 0 12 12" width="10" height="10" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                {delta.direction === 'up' ? <path d="M2 8l4-4 4 4" /> : <path d="M2 4l4 4 4-4" />}
              </svg>
            )}
            {delta.value}
          </span>
        )}
      </div>
      {clamped !== undefined && (
        <div className="h-1.5 w-full rounded-[2px] bg-vp-surface-sunken overflow-hidden" aria-hidden="true">
          <span
            className={cx(
              'block h-full transition-[width] duration-500 ease-vp-out',
              clamped >= 90 ? 'bg-vp-vote-down' : clamped >= 70 ? 'bg-vp-warn' : 'bg-vp-accent',
            )}
            style={{ width: `${clamped}%` }}
          />
        </div>
      )}
      {caption && <span className="text-vp-xs text-vp-text-muted leading-4">{caption}</span>}
    </Tag>
  )
}

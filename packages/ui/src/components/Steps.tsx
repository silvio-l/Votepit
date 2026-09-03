import { cx } from '../lib/cx'

export interface StepItem {
  label: string
}

interface StepsProps {
  items: readonly StepItem[]
  /** Zero-based index of the current step. */
  current: number
  ariaLabel: string
  className?: string
}

function Check() {
  return (
    <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M3.5 8.5l3 3 6-7" />
    </svg>
  )
}

/**
 * Wizard progress: numbered circles joined by a rule; done steps stamp a
 * check, the current one is ink, the rest wait in outline.
 */
export function Steps({ items, current, ariaLabel, className }: StepsProps) {
  return (
    <ol aria-label={ariaLabel} className={cx('flex items-center gap-2 m-0 p-0 list-none', className)}>
      {items.map((item, i) => {
        const done = i < current
        const active = i === current
        return (
          <li key={item.label} className={cx('flex items-center gap-2 min-w-0', i < items.length - 1 && 'flex-1')}>
            <span className="flex items-center gap-2 min-w-0">
              <span
                aria-hidden="true"
                className={cx(
                  'flex size-6 shrink-0 items-center justify-center rounded-full text-vp-2xs font-semibold font-mono-num transition-colors duration-200',
                  done && 'bg-vp-vote-up text-white',
                  active && 'bg-vp-ink text-vp-on-ink shadow-vp-xs',
                  !done && !active && 'border border-vp-border-strong text-vp-text-muted bg-vp-surface',
                )}
              >
                {done ? <Check /> : i + 1}
              </span>
              <span
                aria-current={active ? 'step' : undefined}
                className={cx(
                  'text-vp-sm truncate',
                  active ? 'font-semibold text-vp-ink' : 'text-vp-text-muted',
                  !active && 'hidden sm:inline',
                )}
              >
                {item.label}
              </span>
            </span>
            {i < items.length - 1 && (
              <span
                aria-hidden="true"
                className={cx('h-px flex-1 min-w-4 rounded-full', done ? 'bg-vp-vote-up' : 'bg-vp-border-subtle')}
              />
            )}
          </li>
        )
      })}
    </ol>
  )
}

import { cx } from '../lib/cx'

export interface StepItem {
  label: string
}

interface StepsProps {
  items: readonly StepItem[]
  /** Zero-based index of the current step. */
  current: number
  /** Accessible name of the step list, localized by the app. */
  ariaLabel: string
  className?: string
}

function Check() {
  return (
    <svg
      viewBox="0 0 16 16"
      width="12"
      height="12"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.25"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M3.5 8.5l3 3 6-7" />
    </svg>
  )
}

/**
 * Wizard progress: numbered square markers joined by a rule. Done steps stamp a
 * check, the current one is ink, the rest wait in outline. Inactive labels show
 * from 640 px up only.
 */
export function Steps({ items, current, ariaLabel, className }: StepsProps) {
  return (
    <ol aria-label={ariaLabel} className={cx('ds-steps', className)}>
      {items.map((item, i) => {
        const done = i < current
        const active = i === current
        return (
          <li
            key={item.label}
            className={cx('ds-steps__item', i < items.length - 1 && 'ds-steps__item--grow')}
          >
            <span className="ds-steps__item-head">
              <span
                aria-hidden="true"
                className={cx(
                  'ds-steps__marker',
                  done && 'ds-steps__marker--done',
                  active && 'ds-steps__marker--active',
                  !done && !active && 'ds-steps__marker--pending',
                )}
              >
                {done ? <Check /> : i + 1}
              </span>
              <span
                aria-current={active ? 'step' : undefined}
                className={cx(
                  'ds-steps__label',
                  active && 'ds-steps__label--active',
                  !active && 'ds-steps__label--inactive',
                )}
              >
                {item.label}
              </span>
            </span>
            {i < items.length - 1 && (
              <span
                aria-hidden="true"
                className={cx('ds-steps__rule', done && 'ds-steps__rule--done')}
              />
            )}
          </li>
        )
      })}
    </ol>
  )
}

import { cx } from '../lib/cx'
import { type Status, statusDotClass } from './StatusBadge'

interface StatusFilterProps {
  value: Status | null
  onChange: (s: Status | null) => void
  /** Caller-supplied, localized texts. The component ships no default wording. */
  ariaLabel: string
  allLabel: string
  labels: Record<Status, string>
  /** Optional per-status counts shown after the label. */
  counts?: Partial<Record<Status | 'all', number>>
  /** Category chips to leave out (e.g. a status the board owner hid). */
  hiddenStatuses?: Status[]
  /**
   * Additional chips (e.g. owner-defined statuses), rendered right after
   * the chip of their `status` category and coloured like it. Selecting one
   * calls `onExtraChange`; while `extraValue` is set no category chip and
   * not "all" is active.
   */
  extraOptions?: { key: string; status: Status; label: string }[]
  extraValue?: string | null
  onExtraChange?: (key: string) => void
}

const statusOrder: Status[] = ['open', 'planned', 'in-progress', 'done', 'declined', 'archived']

function Chip({
  active,
  onClick,
  dotClass,
  count,
  children,
}: {
  active: boolean
  onClick: () => void
  dotClass?: string
  count?: number
  children: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cx(
        // Rectangular chips: the active one is an ink plane, the rest are
        // paper with a hairline that turns ink on hover (the landing's
        // ghost button, one weight lighter).
        'inline-flex items-center gap-1.5 h-8 px-3 rounded-vp-full border text-vp-sm font-medium',
        'cursor-pointer vp-press',
        active
          ? 'bg-vp-ink border-vp-ink text-vp-on-ink'
          : 'bg-vp-surface border-vp-rule text-vp-text-secondary hover:text-vp-ink hover:border-vp-ink',
      )}
    >
      {dotClass && (
        <span
          aria-hidden="true"
          className={cx('size-1.5 rounded-vp-full shrink-0', active ? 'bg-vp-on-ink' : dotClass)}
        />
      )}
      {children}
      {count !== undefined && (
        <span
          className={cx(
            'font-mono-num text-vp-2xs leading-none',
            active ? 'text-vp-on-ink-muted' : 'text-vp-text-muted',
          )}
        >
          {count}
        </span>
      )}
    </button>
  )
}

/** Status chips — a filter group of toggle buttons (aria-pressed). */
export function StatusFilter({
  value,
  onChange,
  ariaLabel,
  allLabel,
  labels,
  counts,
  hiddenStatuses = [],
  extraOptions = [],
  extraValue = null,
  onExtraChange,
}: StatusFilterProps) {
  const extraActive = extraValue !== null
  return (
    <div className="flex flex-wrap gap-1" role="group" aria-label={ariaLabel}>
      <Chip
        active={value === null && !extraActive}
        onClick={() => onChange(null)}
        count={counts?.all}
      >
        {allLabel}
      </Chip>
      {statusOrder.map((s) => [
        hiddenStatuses.includes(s) ? null : (
          <Chip
            key={s}
            active={!extraActive && value === s}
            onClick={() => onChange(s)}
            dotClass={statusDotClass[s]}
            count={counts?.[s]}
          >
            {labels[s]}
          </Chip>
        ),
        ...extraOptions
          .filter((o) => o.status === s)
          .map((o) => (
            <Chip
              key={`extra-${o.key}`}
              active={extraValue === o.key}
              onClick={() => onExtraChange?.(o.key)}
              dotClass={statusDotClass[s]}
            >
              {o.label}
            </Chip>
          )),
      ])}
    </div>
  )
}

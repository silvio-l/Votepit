import { cx } from '../lib/cx'
import { type Status, defaultStatusLabels, statusDotClass } from './StatusBadge'

interface StatusFilterProps {
  value: Status | null
  onChange: (s: Status | null) => void
  /** i18n overrides — all default to German so existing callers keep working untranslated. */
  ariaLabel?: string
  allLabel?: string
  labels?: Partial<Record<Status, string>>
  /** Optional per-status counts shown after the label. */
  counts?: Partial<Record<Status | 'all', number>>
}

const statusOrder: Status[] = ['open', 'planned', 'in-progress', 'done', 'declined']

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
        'inline-flex items-center gap-1.5 h-8 px-3 rounded-vp-sm border text-vp-sm font-medium',
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
  ariaLabel = 'Status-Filter',
  allLabel = 'Alle',
  labels = defaultStatusLabels,
  counts,
}: StatusFilterProps) {
  return (
    <div className="flex flex-wrap gap-1" role="group" aria-label={ariaLabel}>
      <Chip active={value === null} onClick={() => onChange(null)} count={counts?.all}>
        {allLabel}
      </Chip>
      {statusOrder.map((s) => (
        <Chip
          key={s}
          active={value === s}
          onClick={() => onChange(s)}
          dotClass={statusDotClass[s]}
          count={counts?.[s]}
        >
          {labels[s] ?? defaultStatusLabels[s]}
        </Chip>
      ))}
    </div>
  )
}

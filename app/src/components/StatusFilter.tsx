import type { Status } from './StatusBadge'

interface StatusFilterProps {
  value: Status | null
  onChange: (s: Status | null) => void
}

const statuses: Array<{ value: Status; label: string }> = [
  { value: 'open', label: 'Offen' },
  { value: 'planned', label: 'Geplant' },
  { value: 'in-progress', label: 'In Arbeit' },
  { value: 'done', label: 'Erledigt' },
  { value: 'declined', label: 'Abgelehnt' },
]

export function StatusFilter({ value, onChange }: StatusFilterProps) {
  return (
    <div className="flex flex-wrap gap-2" role="group" aria-label="Status-Filter">
      {/* "Alle" pill */}
      <button
        type="button"
        onClick={() => onChange(null)}
        aria-pressed={value === null}
        className={[
          'inline-flex items-center gap-1.5',
          'rounded-vp-full px-3 py-1.5',
          'text-[12px] font-inter font-medium',
          'cursor-pointer transition-colors duration-150',
          value === null
            ? 'bg-vp-ink text-vp-on-ink'
            : 'bg-vp-surface-frost border border-vp-border-subtle text-vp-text-secondary hover:text-vp-ink',
        ].join(' ')}
      >
        Alle
      </button>

      {/* Status pills */}
      {statuses.map((s) => {
        const isActive = value === s.value
        return (
          <button
            key={s.value}
            type="button"
            onClick={() => onChange(s.value)}
            aria-pressed={isActive}
            className={[
              'inline-flex items-center gap-1.5',
              'rounded-vp-full px-3 py-1.5',
              'text-[12px] font-inter font-medium',
              'cursor-pointer transition-colors duration-150',
              isActive
                ? 'bg-vp-ink text-vp-on-ink'
                : 'bg-vp-surface-frost border border-vp-border-subtle text-vp-text-secondary hover:text-vp-ink',
            ].join(' ')}
          >
            {s.label}
          </button>
        )
      })}
    </div>
  )
}

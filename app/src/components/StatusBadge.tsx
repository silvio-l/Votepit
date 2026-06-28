export type Status = 'open' | 'planned' | 'in-progress' | 'done' | 'declined'

interface StatusBadgeProps {
  status: Status
}

const statusConfig: Record<Status, { label: string; dotClass: string }> = {
  open: { label: 'Offen', dotClass: 'bg-vp-status-open' },
  planned: { label: 'Geplant', dotClass: 'bg-vp-status-planned' },
  'in-progress': { label: 'In Arbeit', dotClass: 'bg-vp-status-in-progress' },
  done: { label: 'Erledigt', dotClass: 'bg-vp-status-done' },
  declined: { label: 'Abgelehnt', dotClass: 'bg-vp-status-declined' },
}

export function StatusBadge({ status }: StatusBadgeProps) {
  const { label, dotClass } = statusConfig[status]

  return (
    <span className="inline-flex items-center gap-1.5 bg-vp-surface-frost rounded-vp-full px-2 py-1">
      {/* 6px dot */}
      <span
        className={['w-1.5 h-1.5 rounded-full shrink-0', dotClass].join(' ')}
        aria-hidden="true"
      />
      <span className="text-[12px] font-inter text-vp-text-secondary leading-none">{label}</span>
    </span>
  )
}

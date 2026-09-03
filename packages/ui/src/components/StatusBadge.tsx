import { cx } from '../lib/cx'

export type Status = 'open' | 'planned' | 'in-progress' | 'done' | 'declined'

interface StatusBadgeProps {
  status: Status
  /** i18n override — defaults to German so existing callers keep working untranslated. */
  label?: string
  /** "chip" draws a tinted pill; "text" (default) is the bare dot + word. */
  variant?: 'text' | 'chip'
  className?: string
}

/** German defaults, exported so StatusFilter shares the same base labels + callers can translate. */
export const defaultStatusLabels: Record<Status, string> = {
  open: 'Offen',
  planned: 'Geplant',
  'in-progress': 'In Arbeit',
  done: 'Erledigt',
  declined: 'Abgelehnt',
}

export const statusDotClass: Record<Status, string> = {
  open: 'bg-vp-status-open',
  planned: 'bg-vp-status-planned',
  'in-progress': 'bg-vp-status-in-progress',
  done: 'bg-vp-status-done',
  declined: 'bg-vp-status-declined',
}

const chipClass: Record<Status, string> = {
  open: 'bg-vp-surface-frost border-vp-border-subtle text-vp-text-secondary',
  planned: 'bg-vp-info-soft border-vp-info/25 text-vp-info-strong',
  'in-progress': 'bg-vp-warn-soft border-vp-warn/25 text-vp-warn-strong',
  done: 'bg-vp-vote-up-soft border-vp-vote-up/25 text-vp-vote-up-strong',
  declined: 'bg-vp-vote-down-soft border-vp-vote-down/25 text-vp-vote-down-strong',
}

/** Status word with its colour dot — colour never carries the meaning alone. */
export function StatusBadge({
  status,
  label = defaultStatusLabels[status],
  variant = 'text',
  className,
}: StatusBadgeProps) {
  return (
    <span
      className={cx(
        'inline-flex items-center gap-1.5 text-vp-xs font-medium whitespace-nowrap',
        variant === 'chip'
          ? cx('h-5 px-1.5 rounded-vp-full border leading-none', chipClass[status])
          : 'text-vp-text-secondary',
        className,
      )}
    >
      <span
        className={cx(
          'size-1.5 rounded-full shrink-0',
          statusDotClass[status],
          status === 'in-progress' && variant === 'chip' && 'vp-live-dot text-vp-status-in-progress',
        )}
        aria-hidden="true"
      />
      {label}
    </span>
  )
}

import { cx } from '../lib/cx'
import { Icon, type IconName } from './icons'

/** Semantic tones only. Never an error colour: an unwanted idea is "neutral with an explaining icon". */
export type StatusTone = 'neutral' | 'info' | 'planned' | 'progress' | 'success'

export type IdeaStatus =
  | 'open'
  | 'in-review'
  | 'planned'
  | 'in-progress'
  | 'done'
  | 'declined'
  | 'not-implemented'
  | 'archived'

const STATUS_MAP: Record<IdeaStatus, { tone: StatusTone; icon: IconName }> = {
  open: { tone: 'info', icon: 'circle' }, // owner decision 2026-10-22: Open = info blue
  'in-review': { tone: 'info', icon: 'info' },
  planned: { tone: 'planned', icon: 'list' }, // owner decision 2026-10-22: own plum tone
  'in-progress': { tone: 'progress', icon: 'clock' },
  done: { tone: 'success', icon: 'check-circle' },
  declined: { tone: 'neutral', icon: 'minus-circle' },
  'not-implemented': { tone: 'neutral', icon: 'minus-circle' },
  archived: { tone: 'neutral', icon: 'minus-circle' },
}

const TONE_ICON: Record<StatusTone, IconName> = {
  neutral: 'circle',
  info: 'info',
  planned: 'list',
  progress: 'clock',
  success: 'check-circle',
}

/** Tone of an idea status (colour family). The same mapping is used in list, detail and roadmap. */
export function statusToneFor(status: IdeaStatus): StatusTone {
  return STATUS_MAP[status].tone
}

/**
 * Tone, icon and the caller's localized label for an idea status. The same mapping is
 * used in list, detail and roadmap, so a status reads the same everywhere.
 */
export function statusChipFor(
  status: IdeaStatus,
  label: string,
): { tone: StatusTone; icon: IconName; label: string } {
  return { ...STATUS_MAP[status], label }
}

export interface StatusChipProps {
  tone?: StatusTone
  /** Icon shown before the text. Defaults to the tone's icon. */
  icon?: IconName
  children: string
  testId?: string
}

/** Status label with icon and text. Colour is never the only signal. */
export function StatusChip({
  tone = 'neutral',
  icon,
  children,
  testId = 'status-chip',
}: StatusChipProps) {
  return (
    <span
      className={cx('ds-status-chip', `ds-status-chip--${tone}`)}
      data-tone={tone}
      data-testid={testId}
    >
      <Icon name={icon ?? TONE_ICON[tone]} size="small" />
      <span>{children}</span>
    </span>
  )
}

import { type ReactNode, useId } from 'react'
import { Button } from './Button'
import { Icon, type IconName } from './icons'

export type DecisionOutcome = 'planned' | 'declined' | 'done'

export interface DecisionPanelProps {
  outcome: DecisionOutcome
  /** Author or responsible team. Show only when the viewer may see it. */
  author?: string
  /** ISO date (yyyy-mm-dd) of the decision. */
  date?: string
  /** Reason for the decision, written respectfully, also for declined ideas. */
  rationale: ReactNode
  /** What happens next, or what is not planned. */
  nextStep: ReactNode
  /** Edit action is rendered only when true (the server still checks the permission). */
  canEdit?: boolean
  onEdit?: () => void
  /** Visible label before the next step. */
  nextStepLabel: string
  /** Visible text of the edit button. */
  editLabel: string
  /** Headings per outcome. */
  headings: Record<DecisionOutcome, string>
  /** Stable data-testid on the panel. The edit button gets `<testId>-edit`. */
  testId: string
}

const ICONS: Record<DecisionOutcome, IconName> = {
  planned: 'list',
  declined: 'minus-circle',
  done: 'check-circle',
}

const dateFormat = new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: 'long',
  year: 'numeric',
})

function formatDate(iso: string): string {
  // Parsed as a local date so the day does not shift with the time zone.
  const parsed = new Date(`${iso}T00:00:00`)
  return Number.isNaN(parsed.getTime()) ? iso : dateFormat.format(parsed)
}

/**
 * Decision block for an idea: outcome, author or team, date, rationale and next step.
 * "Planned" is not a delivery promise; there is no invented date.
 */
export function DecisionPanel({
  outcome,
  author,
  date,
  rationale,
  nextStep,
  canEdit = false,
  onEdit,
  nextStepLabel,
  editLabel,
  headings,
  testId,
}: DecisionPanelProps) {
  const id = useId()
  const headingId = `${id}-heading`

  return (
    <section
      className={`ds-decision-panel ds-decision-panel--${outcome}`}
      aria-labelledby={headingId}
      data-testid={testId}
    >
      <h3 id={headingId} className="ds-decision-panel__heading">
        <Icon name={ICONS[outcome]} />
        <span>{headings[outcome]}</span>
      </h3>
      {(author || date) && (
        <p className="ds-decision-panel__meta">
          {author && <span>{author}</span>}
          {date && <time dateTime={date}>{formatDate(date)}</time>}
        </p>
      )}
      <p className="ds-decision-panel__text">{rationale}</p>
      <p className="ds-decision-panel__next">
        <span>{nextStepLabel} </span>
        {nextStep}
      </p>
      {canEdit && onEdit && (
        <div className="ds-decision-panel__actions">
          <Button variant="secondary" icon="pencil" testId={`${testId}-edit`} onClick={onEdit}>
            {editLabel}
          </Button>
        </div>
      )}
    </section>
  )
}

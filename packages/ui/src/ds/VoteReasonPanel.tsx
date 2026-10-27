import { type ReactNode, useId } from 'react'
import { cx } from '../lib/cx'
import { Button } from './Button'
import { TextArea } from './Field'

/** The four fixed reasons for a vote against (P-03). */
export type VoteReasonChoice = 'not_needed' | 'too_expensive' | 'worsens_workflow' | 'other'

export const VOTE_REASON_CHOICES: readonly VoteReasonChoice[] = [
  'not_needed',
  'too_expensive',
  'worsens_workflow',
  'other',
]

export interface VoteReasonPanelLabels {
  question: string
  options: Record<VoteReasonChoice, string>
  noteLabel: string
  counter: (count: number, max: number) => string
  save: string
  skip: string
  privacy: string
  saved: string
  /** Announced once when the note reaches maxLength minus 20 characters. */
  nearLimit?: string
  /** Announced once when the note reaches maxLength. */
  limitReached?: string
}

export type VoteReasonPanelStatus = 'idle' | 'saving' | 'saved' | 'error'

export interface VoteReasonPanelProps {
  /** Prefix for all test ids, e.g. `idea-reason`. */
  testId: string
  reason: VoteReasonChoice | null
  note: string
  maxLength: number
  status: VoteReasonPanelStatus
  /** Validation or save error; announced as an alert. */
  error: string | null
  labels: VoteReasonPanelLabels
  onReasonChange: (reason: VoteReasonChoice) => void
  onNoteChange: (note: string) => void
  onSave: () => void
  onSkip: () => void
}

/**
 * Optional follow-up after a vote against. Never blocks: the vote is already saved,
 * and skipping leaves it unchanged.
 */
export function VoteReasonPanel({
  testId,
  reason,
  note,
  maxLength,
  status,
  error,
  labels,
  onReasonChange,
  onNoteChange,
  onSave,
  onSkip,
}: VoteReasonPanelProps) {
  const questionId = useId()
  const noteLength = Array.from(note).length
  const busy = status === 'saving'
  // Only the two thresholds are announced; every other keystroke stays silent.
  const nearAt = maxLength - 20
  const announcement =
    noteLength === nearAt ? (labels.nearLimit ?? '') : noteLength === maxLength ? (labels.limitReached ?? '') : ''

  return (
    <div
      className="ds-vote-reason"
      data-testid={testId}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          event.preventDefault()
          onSkip()
        }
      }}
    >
      <p id={questionId} className="ds-vote-reason__question">
        {labels.question}
      </p>
      <div role="radiogroup" aria-labelledby={questionId} className="ds-vote-reason__options">
        {VOTE_REASON_CHOICES.map((choice) => (
          <label
            key={choice}
            className={cx('ds-vote-reason__option', reason === choice && 'is-selected')}
          >
            <input
              type="radio"
              name={`${testId}-reason`}
              value={choice}
              checked={reason === choice}
              disabled={busy}
              onChange={() => onReasonChange(choice)}
              data-testid={`${testId}-option-${choice}`}
            />
            <span>{labels.options[choice]}</span>
          </label>
        ))}
      </div>
      <TextArea
        label={labels.noteLabel}
        value={note}
        maxLength={maxLength}
        rows={2}
        disabled={busy}
        onChange={(event) => onNoteChange(event.target.value)}
        testId={`${testId}-note`}
      />
      <p className="ds-vote-reason__counter" aria-hidden="true">
        {labels.counter(noteLength, maxLength)}
      </p>
      <p aria-live="polite" className="ds-vote-reason__live" data-testid={`${testId}-live`}>
        {announcement}
      </p>
      <p className="ds-vote-reason__privacy">{labels.privacy}</p>
      {error !== null && (
        <p role="alert" className="ds-vote-reason__error">
          {error}
        </p>
      )}
      {status === 'saved' ? (
        <p role="status" className="ds-vote-reason__saved">
          {labels.saved}
        </p>
      ) : (
        <div className="ds-vote-reason__actions">
          <Button variant="text" testId={`${testId}-skip`} onClick={onSkip} disabled={busy}>
            {labels.skip}
          </Button>
          <Button
            variant="primary"
            testId={`${testId}-save`}
            onClick={onSave}
            loading={busy}
            disabled={reason === null}
          >
            {labels.save}
          </Button>
        </div>
      )}
    </div>
  )
}

export interface VoteReasonAggregate {
  down_count: number
  counts: Record<VoteReasonChoice, number>
  notes: { reason: VoteReasonChoice; note: string }[]
}

export interface VoteReasonAggregateLabels {
  title: string
  empty: string
  downCount: (count: number) => string
  textsTitle: string
  noNames: string
  options: Record<VoteReasonChoice, string>
}

export interface VoteReasonAggregateViewProps {
  testId: string
  labels: VoteReasonAggregateLabels
  aggregate: VoteReasonAggregate
  /** Optional per-text action (operator takedown), rendered after each free text. */
  renderNoteAction?: (item: VoteReasonAggregate['notes'][number], index: number) => ReactNode
}

/** Operator view: counts per reason and free texts, never with a name or identity. */
export function VoteReasonAggregateView({ testId, labels, aggregate, renderNoteAction }: VoteReasonAggregateViewProps) {
  return (
    <section className="ds-vote-reason-aggregate" data-testid={testId}>
      {labels.title !== '' && <h3 className="ds-vote-reason-aggregate__title">{labels.title}</h3>}
      {aggregate.down_count === 0 ? (
        <p className="ds-vote-reason-aggregate__empty">{labels.empty}</p>
      ) : (
        <>
          <p className="ds-vote-reason-aggregate__total">{labels.downCount(aggregate.down_count)}</p>
          <ul className="ds-vote-reason-aggregate__counts">
            {VOTE_REASON_CHOICES.map((choice) => (
              <li key={choice}>
                <span>{labels.options[choice]}</span>{' '}
                <strong data-testid={`${testId}-count-${choice}`}>{aggregate.counts[choice]}</strong>
              </li>
            ))}
          </ul>
          {aggregate.notes.length > 0 && (
            <>
              <h4 className="ds-vote-reason-aggregate__subtitle">{labels.textsTitle}</h4>
              <p className="ds-vote-reason-aggregate__no-names">{labels.noNames}</p>
              <ul className="ds-vote-reason-aggregate__notes">
                {aggregate.notes.map((item, index) => (
                  <li key={index}>
                    <span className="ds-vote-reason-aggregate__note-reason">
                      {labels.options[item.reason]}
                    </span>
                    <span className="ds-vote-reason-aggregate__note-text">{item.note}</span>
                    {renderNoteAction?.(item, index)}
                  </li>
                ))}
              </ul>
            </>
          )}
        </>
      )}
    </section>
  )
}

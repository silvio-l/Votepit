import { cx } from '../lib/cx'
import { formatApproval, formatNet, meterSegments, summarizeVotes } from './voteSummary'

export type VoteSummaryField = 'up' | 'down' | 'net' | 'approval'

export interface VoteSummaryLabels {
  up: string
  down: string
  net: string
  approval: string
  empty: string
  explanation: string
  /** Shown next to the approval while there are few votes (owner decision 2026-10-22). */
  fewVotes: string
}

export interface VoteSummaryProps {
  up: number
  down: number
  variant?: 'standard' | 'compact'
  /** Which values to show. Default: all four. The idea card shows only net and approval, since its vote buttons show the counts. */
  fields?: VoteSummaryField[]
  /** Standard only: the explanatory line under the values. Default true. */
  showExplanation?: boolean
  /** Two-segment approval bar under the values. Default: standard only; the idea card turns it on. */
  meter?: boolean
  testId?: string
  /** Visible texts, localized by the app. */
  labels: VoteSummaryLabels
  /** BCP 47 locale for number formatting. */
  locale: string
}

const ALL_FIELDS: VoteSummaryField[] = ['up', 'down', 'net', 'approval']

/**
 * Absolute up/down counts, net and approval as separate labelled values. Zero votes show
 * "No votes yet" and no percentage. The two-segment meter is only a supplement;
 * every value is also in text. All arithmetic comes from voteSummary.ts.
 */
export function VoteSummary({
  up,
  down,
  variant = 'standard',
  fields = ALL_FIELDS,
  showExplanation = true,
  meter = variant === 'standard',
  testId = 'vote-summary',
  labels,
  locale,
}: VoteSummaryProps) {
  const summary = summarizeVotes(up, down)
  const text = labels
  const countFormat = new Intl.NumberFormat(locale)
  const isEmpty = summary.total === 0
  const segments = meterSegments(summary)
  const shows = (field: VoteSummaryField) => fields.includes(field)

  return (
    <div
      className={cx('ds-vote-summary', `ds-vote-summary--${variant}`)}
      data-testid={testId}
      data-empty={isEmpty || undefined}
    >
      {isEmpty && <p className="ds-vote-summary__empty">{text.empty}</p>}
      <dl className="ds-vote-summary__stats">
        {shows('up') && (
          <div className="ds-vote-summary__item">
            <dt className="ds-vote-summary__label">{text.up}</dt>
            <dd
              className="ds-vote-summary__value ds-vote-summary__value--up"
              data-testid={`${testId}-up`}
            >
              {countFormat.format(summary.up)}
            </dd>
          </div>
        )}
        {shows('down') && (
          <div className="ds-vote-summary__item">
            <dt className="ds-vote-summary__label">{text.down}</dt>
            <dd
              className="ds-vote-summary__value ds-vote-summary__value--down"
              data-testid={`${testId}-down`}
            >
              {countFormat.format(summary.down)}
            </dd>
          </div>
        )}
        {shows('net') && !isEmpty && (
          <div className="ds-vote-summary__item">
            <dt className="ds-vote-summary__label">{text.net}</dt>
            <dd className="ds-vote-summary__value" data-testid={`${testId}-net`}>
              {formatNet(summary.net, locale)}
            </dd>
          </div>
        )}
        {shows('approval') && !isEmpty && (
          <div className="ds-vote-summary__item">
            <dt className="ds-vote-summary__label">{text.approval}</dt>
            <dd className="ds-vote-summary__value" data-testid={`${testId}-approval`}>
              {formatApproval(summary.approvalRounded, locale)}
            </dd>
          </div>
        )}
      </dl>
      {shows('approval') && summary.fewVotes && (
        <p className="ds-vote-summary__few" data-testid={`${testId}-few`}>
          {text.fewVotes}
        </p>
      )}
      {meter && (
        <div
          className={cx('ds-vote-summary__meter', isEmpty && 'ds-vote-summary__meter--empty')}
          aria-hidden="true"
        >
          {segments && (
            <>
              <span
                className="ds-vote-summary__segment ds-vote-summary__segment--up"
                style={{ width: `${segments.up * 100}%` }}
              />
              <span
                className="ds-vote-summary__segment ds-vote-summary__segment--down"
                style={{ width: `${segments.down * 100}%` }}
              />
            </>
          )}
        </div>
      )}
      {variant === 'standard' && showExplanation && (
        <p className="ds-vote-summary__explain">{text.explanation}</p>
      )}
    </div>
  )
}

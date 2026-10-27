import { Badge } from '@votepit/ui/ds'
import type { StatusDecision } from '../lib/api/model'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

interface DecisionBlockProps {
  /** Latest status decision; the block renders nothing when none was recorded. */
  decision: StatusDecision | null | undefined
  /** Newest-first history, the latest entry included. */
  history: StatusDecision[]
  /** Board-aware label of a status key (custom statuses included). */
  statusLabel: (key: string) => string
}

/**
 * "Entscheidung" block on the idea detail (P-01): status, public reason, next
 * step, responsible role label and date, plus a collapsible history.
 * All user text is rendered as React text (escaped), never as Markdown/HTML.
 */
export function DecisionBlock({ decision, history, statusLabel }: DecisionBlockProps) {
  const t = useT('statusDecision')
  const { language } = useI18n()
  if (!decision) return null

  return (
    <section
      data-testid="idea-decision-block"
      aria-labelledby="idea-decision-title"
      className="mt-5 rounded-vp-md border border-vp-rule bg-vp-surface p-3 sm:p-4"
    >
      <h2 id="idea-decision-title" className="vp-eyebrow mb-2">
        {t('decisionTitle')}
      </h2>
      <p className="text-vp-sm text-vp-text-secondary">
        <span data-testid="idea-decision-status" className="font-semibold text-vp-ink">
          {statusLabel(decision.status_to)}
        </span>
        <span aria-hidden="true"> · </span>
        <span data-testid="idea-decision-date">
          {formatDateTime(decision.created_at, language)}
        </span>
      </p>

      {decision.reason !== null ? (
        <p
          data-testid="idea-decision-reason-text"
          className="mt-2 whitespace-pre-line break-words text-vp-md text-vp-ink leading-6"
        >
          {decision.reason}
        </p>
      ) : (
        <p className="mt-2 text-vp-sm text-vp-text-muted">{t('noReason')}</p>
      )}

      {decision.next_step !== null && (
        <p
          data-testid="idea-decision-next-step-text"
          className="mt-2 text-vp-sm text-vp-text-secondary break-words"
        >
          <span className="font-semibold">{t('nextStepLabel')}: </span>
          {decision.next_step}
        </p>
      )}

      {decision.responsible_role !== null && (
        <p data-testid="idea-decision-role-text" className="mt-1 text-vp-sm text-vp-text-secondary">
          <span className="font-semibold">{t('roleLabel')}: </span>
          {decision.responsible_role === 'team' ? t('roleTeam') : t('roleModeration')}
        </p>
      )}

      {history.length > 0 && (
        <details data-testid="idea-decision-history" className="mt-3 border-t border-vp-rule pt-2">
          <summary
            data-testid="idea-decision-history-toggle"
            className="cursor-pointer text-vp-sm font-semibold text-vp-ink max-sm:min-h-11 max-sm:flex max-sm:items-center"
          >
            {t('historyTitle')} ({history.length})
          </summary>
          <ol className="mt-2 flex flex-col gap-3">
            {history.map((entry) => (
              <li
                key={entry.id}
                data-testid={`idea-decision-history-${entry.id}`}
                className="text-vp-sm"
              >
                <span className="font-semibold text-vp-ink">{statusLabel(entry.status_to)}</span>
                <span className="text-vp-text-muted">
                  {' · '}
                  {formatDateTime(entry.created_at, language)}
                </span>
                <p className="mt-0.5 whitespace-pre-line break-words text-vp-text-secondary">
                  {entry.reason ?? t('noReason')}
                </p>
              </li>
            ))}
          </ol>
        </details>
      )}
    </section>
  )
}

/**
 * Subtle indicator on a board row whose latest decision has a public reason.
 * Flag only: the row never carries the reason text (P-01 list budget).
 */
export function DecisionReasonFlag({ ideaId }: { ideaId: number }) {
  const t = useT('statusDecision')
  return (
    <span data-testid={`board-idea-${ideaId}-reason`} className="inline-flex">
      <Badge tone="info">{t('cardBadge')}</Badge>
    </span>
  )
}

import { CalendarClock } from 'lucide-react'
import type { BoardPhasesInfo } from '../lib/api'
import { phaseMessage } from '../lib/boardPhases'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

/**
 * The board's optional submission/voting windows as one quiet line
 * (broaden-use-cases issue 04). Renders the SERVER-computed state only —
 * a board without any deadline renders nothing, so default boards look
 * exactly as before. Dates are shown in the viewer's local time zone.
 */
export function BoardPhaseLine({
  phases,
  id,
  className = '',
}: {
  phases: BoardPhasesInfo | undefined
  id?: string
  className?: string
}) {
  const tCommon = useT('common')
  const { language } = useI18n()

  const parts = (['submissions', 'voting'] as const)
    .map((phase) => {
      const message = phaseMessage(phases?.[phase], phase)
      if (message === null) return null
      return {
        phase,
        closed: phases?.[phase].state !== 'open',
        text: tCommon(message.key, {
          date: message.date !== null ? formatDateTime(message.date, language) : '',
        }),
      }
    })
    .filter((p) => p !== null)

  if (parts.length === 0) return null

  return (
    <p
      id={id}
      className={`flex flex-wrap items-center gap-x-2 gap-y-1 text-vp-sm text-vp-text-secondary ${className}`}
      data-testid="board-phase-line"
    >
      <CalendarClock size={15} aria-hidden="true" className="shrink-0 text-vp-text-muted" />
      <span className="sr-only">{tCommon('phase.lineLabel')}:</span>
      {parts.map((p, i) => (
        <span key={p.phase} className="flex items-center gap-2">
          {i > 0 && <span aria-hidden="true">·</span>}
          <span className={p.closed ? 'font-medium text-vp-ink' : undefined}>{p.text}</span>
        </span>
      ))}
    </p>
  )
}

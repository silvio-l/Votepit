import { cx } from '../lib/cx'
import { ConsensusBar } from './ConsensusBar'

/**
 * RoadmapCard — one read-only line in a roadmap column.
 *
 * No VoteWidget — the score is a static figure with a "votes" label. The whole
 * line links to the idea detail view, where the ballot lives. It carries no
 * sheet of its own: the column IS the sheet, and these lines rule themselves
 * against each other with hairlines (nothing is nested inside another sheet).
 */
interface RoadmapCardProps {
  id: string | number
  title: string
  score: number
  consensusPercent: number | null
  /** Link to the idea detail view (e.g. /{board}/idea/{id}) */
  href?: string
  /** i18n overrides — default to German so existing callers keep working untranslated. */
  votesLabel?: string
  consensusLabel?: string
  consensusLowLabel?: string
  consensusEmptyLabel?: string
}

export function RoadmapCard({
  title,
  score,
  consensusPercent,
  href,
  votesLabel = 'Stimmen',
  consensusLabel,
  consensusLowLabel,
  consensusEmptyLabel,
}: RoadmapCardProps) {
  const inner = (
    <div
      className={cx(
        'flex flex-col gap-2.5 px-3 sm:px-4 py-3 transition-colors duration-150',
        href && 'hover:bg-vp-ink-softer',
      )}
    >
      <p
        className={cx(
          'text-vp-base font-semibold text-vp-ink leading-5 line-clamp-3 text-pretty',
          href && 'transition-colors group-hover:text-vp-accent-strong',
        )}
      >
        {title}
      </p>
      <div className="flex items-baseline gap-1.5">
        <span className="font-mono-num font-bold text-vp-lg leading-none text-vp-ink tracking-tight">{score}</span>
        <span className="text-vp-xs text-vp-text-muted leading-none">{votesLabel}</span>
      </div>
      <ConsensusBar
        percent={consensusPercent}
        label={consensusLabel}
        labelLow={consensusLowLabel}
        labelEmpty={consensusEmptyLabel}
        size="compact"
      />
    </div>
  )

  if (href) {
    return (
      <a href={href} className="group block no-underline">
        {inner}
      </a>
    )
  }

  return inner
}

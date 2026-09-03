import { cx } from '../lib/cx'
import { ConsensusBar } from './ConsensusBar'
import type { Status } from './StatusBadge'
import { StatusBadge } from './StatusBadge'

/**
 * RoadmapRow — read-only roadmap line.
 *
 * No VoteWidget — the score is a static figure. The title is a stretched link
 * to the idea detail view, where the ballot lives.
 */
interface RoadmapRowProps {
  id: string | number
  title: string
  excerpt?: string
  status: Status
  score: number
  commentCount: number
  consensusPercent: number | null
  /** Link to the idea detail view (e.g. /{board}/idea/{id}) */
  href?: string
  /** i18n overrides — all default to German so existing callers keep working untranslated. */
  statusLabel?: string
  votesLabel?: string
  commentLabel?: string
  commentsLabel?: string
  consensusLabel?: string
  consensusLowLabel?: string
  consensusEmptyLabel?: string
}

export function RoadmapRow({
  title,
  excerpt,
  status,
  score,
  commentCount,
  consensusPercent,
  href,
  statusLabel,
  votesLabel = 'Stimmen',
  commentLabel = 'Kommentar',
  commentsLabel = 'Kommentare',
  consensusLabel,
  consensusLowLabel,
  consensusEmptyLabel,
}: RoadmapRowProps) {
  const titleClass = 'text-vp-md font-semibold text-vp-ink leading-6 text-pretty tracking-[-0.005em]'

  return (
    <div
      className={cx(
        'group relative flex items-start gap-3 sm:gap-4 px-3 sm:px-4 py-3.5 transition-colors duration-150',
        href && 'hover:bg-vp-ink-softer',
      )}
    >
      <div className="shrink-0 w-11 flex flex-col items-center justify-center rounded-vp-md bg-vp-surface-frost ring-1 ring-vp-border-subtle py-1.5">
        <span className="font-mono-num font-bold text-vp-md leading-none text-vp-ink tracking-tight">{score}</span>
        <span className="text-[10px] uppercase tracking-[0.06em] text-vp-text-muted leading-none mt-1">
          {votesLabel}
        </span>
      </div>

      <div className="flex-1 min-w-0 flex flex-col gap-1 pt-0.5">
        {href ? (
          <a
            href={href}
            className={cx(
              titleClass,
              'no-underline group-hover:text-vp-accent-strong transition-colors duration-150',
              "after:absolute after:inset-0 after:content-['']",
            )}
          >
            {title}
          </a>
        ) : (
          <p className={titleClass}>{title}</p>
        )}
        {excerpt && (
          <p className="text-vp-sm text-vp-text-secondary leading-5 line-clamp-2 sm:line-clamp-1">
            {excerpt}
          </p>
        )}
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-vp-xs text-vp-text-muted pt-0.5">
          <StatusBadge status={status} label={statusLabel} variant="chip" />
          <span>
            {commentCount} {commentCount === 1 ? commentLabel : commentsLabel}
          </span>
        </div>
      </div>

      <div className="hidden md:block w-36 shrink-0 self-center">
        <ConsensusBar
          percent={consensusPercent}
          label={consensusLabel}
          labelLow={consensusLowLabel}
          labelEmpty={consensusEmptyLabel}
        />
      </div>

      {href && (
        <svg
          viewBox="0 0 16 16"
          width="16"
          height="16"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.75"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
          className="hidden sm:block shrink-0 self-center text-vp-text-tertiary transition-all duration-150 group-hover:text-vp-ink group-hover:translate-x-0.5"
        >
          <path d="M6 3l5 5-5 5" />
        </svg>
      )}
    </div>
  )
}

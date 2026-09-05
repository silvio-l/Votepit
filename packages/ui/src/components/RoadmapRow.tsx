import type { ReactNode } from 'react'
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
  excerpt?: ReactNode
  status: Status
  score: number
  commentCount: number
  consensusPercent: number | null
  /** Raw FOR / AGAINST counts — shown as "140 / 12", as on the board's ballot lines. */
  upCount?: number
  downCount?: number
  /** Link to the idea detail view (e.g. /{board}/idea/{id}) */
  href?: string
  /** i18n overrides — all default to German so existing callers keep working untranslated. */
  statusLabel?: string
  votesLabel?: string
  commentLabel?: string
  commentsLabel?: string
  /** Screen-reader words in front of the FOR / AGAINST counts. */
  forLabel?: string
  againstLabel?: string
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
  upCount,
  downCount,
  href,
  statusLabel,
  votesLabel = 'Stimmen',
  commentLabel = 'Comment',
  commentsLabel = 'Comments',
  forLabel = 'for',
  againstLabel = 'against',
  consensusLabel,
  consensusLowLabel,
  consensusEmptyLabel,
}: RoadmapRowProps) {
  const contested = consensusPercent !== null && consensusPercent < 50
  const hasSplit = upCount !== undefined && downCount !== undefined
  const titleClass = 'text-vp-md font-semibold text-vp-ink leading-6 text-pretty tracking-[-0.01em]'

  return (
    <div
      className={cx(
        'group relative flex items-start gap-3 sm:gap-4 px-3 sm:px-4 py-3 transition-colors duration-150',
        href && 'hover:bg-vp-surface-frost',
      )}
    >
      {/* The tally in the margin — square and sunken, standing in for the
          ballot the board carries here (the roadmap is read-only). */}
      <div className="shrink-0 w-11 flex flex-col items-center justify-center rounded-vp-sm bg-vp-surface-frost border border-vp-rule py-1.5">
        <span className="font-mono-num font-bold text-vp-md leading-none text-vp-ink tracking-tight">
          {score}
        </span>
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
              'no-underline decoration-2 underline-offset-[0.2em] decoration-vp-underline',
              'group-hover:underline transition-colors duration-150',
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
        {/* Same meta line as the board's ballot lines: status · split · comments. */}
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-vp-xs text-vp-text-muted pt-0.5">
          <StatusBadge status={status} label={statusLabel} variant="text" />
          {hasSplit && (
            <>
              <span aria-hidden="true">·</span>
              <span
                className={cx(
                  'font-mono-num font-semibold',
                  contested ? 'text-vp-vote-down-strong' : 'text-vp-text-secondary',
                )}
              >
                <span className="sr-only">{forLabel}: </span>
                {upCount}
                <span aria-hidden="true"> / </span>
                <span className="sr-only">, {againstLabel}: </span>
                {downCount}
              </span>
            </>
          )}
          <span aria-hidden="true">·</span>
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
          className="hidden sm:block shrink-0 self-center text-vp-text-tertiary transition-all duration-150 ease-vp-out group-hover:text-vp-ink group-hover:translate-x-0.5"
        >
          <path d="M6 3l5 5-5 5" />
        </svg>
      )}
    </div>
  )
}

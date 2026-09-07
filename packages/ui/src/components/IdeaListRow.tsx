import type { ReactNode } from 'react'
import { cx } from '../lib/cx'
import { ConsensusBar } from './ConsensusBar'
import type { Status } from './StatusBadge'
import { StatusBadge } from './StatusBadge'
import type { UserVote } from './VoteWidget'
import { VoteWidget } from './VoteWidget'

interface IdeaListRowProps {
  id: string | number
  title: string
  excerpt?: ReactNode
  status: Status
  score: number
  commentCount: number
  timeAgo: string
  consensusPercent: number | null
  /** Raw FOR / AGAINST counts — shown as "140 / 12" in the meta row when given. */
  upCount?: number
  downCount?: number
  userVote?: UserVote
  onVoteUp?: () => void
  onVoteDown?: () => void
  href?: string
  /** i18n overrides — all default to German so existing callers keep working untranslated. */
  statusLabel?: string
  commentLabel?: string
  commentsLabel?: string
  /** Screen-reader words in front of the FOR / AGAINST counts. */
  forLabel?: string
  againstLabel?: string
  consensusLabel?: string
  consensusLowLabel?: string
  consensusEmptyLabel?: string
  upAriaLabel?: string
  downAriaLabel?: string
  /** Pinned rows carry a small marker. */
  pinned?: boolean
  pinnedLabel?: string
}

function CommentGlyph() {
  return (
    <svg
      viewBox="0 0 16 16"
      width="13"
      height="13"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className="shrink-0"
    >
      <path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z" />
    </svg>
  )
}

function PinGlyph() {
  return (
    <svg
      viewBox="0 0 16 16"
      width="12"
      height="12"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className="shrink-0"
    >
      <path d="M9.5 2l4.5 4.5-2 1-1.5 4-2-2L4 14l-2-2 4.5-4.5-2-2 4-1.5z" />
    </svg>
  )
}

/**
 * One ballot line on the result sheet — the same row the landing's
 * ExampleBoard draws: the marks, the title (a stretched link covering the
 * row), a meta line of status · counts · age, and the division bar in the
 * margin. Rows rule themselves against each other — the parent list owns
 * the sheet. A contested row (< 50% for) tints its numbers coral.
 */
export function IdeaListRow({
  title,
  excerpt,
  status,
  score,
  commentCount,
  timeAgo,
  consensusPercent,
  upCount,
  downCount,
  userVote,
  onVoteUp,
  onVoteDown,
  href,
  statusLabel,
  commentLabel = 'Comment',
  commentsLabel = 'Comments',
  forLabel = 'for',
  againstLabel = 'against',
  consensusLabel,
  consensusLowLabel,
  consensusEmptyLabel,
  upAriaLabel,
  downAriaLabel,
  pinned = false,
  pinnedLabel = 'Pinned',
}: IdeaListRowProps) {
  const contested = consensusPercent !== null && consensusPercent < 50
  const titleClass =
    'text-vp-md font-semibold text-vp-ink leading-6 text-pretty tracking-[-0.01em]'
  const hasSplit = upCount !== undefined && downCount !== undefined

  return (
    <div
      className={cx(
        'group relative flex items-start gap-3 sm:gap-4 px-3 sm:px-4 py-3',
        'transition-colors duration-150',
        href && 'hover:bg-vp-surface-frost',
        pinned && 'bg-vp-accent-soft/30',
      )}
    >
      {/* Marks sit above the stretched title link — and, while a vote effect
          is playing, above the neighbouring rows too, so a particle that
          travels past this row's boundary isn't painted over by the next
          row's own vote column (see tokens.css `.vp-vote-stack`). */}
      <div className="vp-vote-stack shrink-0">
        <VoteWidget
          tone="neutral"
          score={score}
          userVote={userVote}
          onVoteUp={onVoteUp}
          onVoteDown={onVoteDown}
          upAriaLabel={upAriaLabel}
          downAriaLabel={downAriaLabel}
        />
      </div>

      <div className="flex-1 min-w-0 flex flex-col gap-1 pt-0.5">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
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
          {pinned && (
            <span className="inline-flex items-center gap-1 h-5 px-1.5 rounded-vp-sm bg-vp-accent-soft text-vp-2xs font-semibold text-vp-accent-strong">
              <PinGlyph />
              {pinnedLabel}
            </span>
          )}
        </div>
        {excerpt && (
          <p className="text-vp-sm text-vp-text-secondary leading-5 line-clamp-2 sm:line-clamp-1">
            {excerpt}
          </p>
        )}
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
          <span className="inline-flex items-center gap-1">
            <CommentGlyph />
            {commentCount} {commentCount === 1 ? commentLabel : commentsLabel}
          </span>
          <span aria-hidden="true">·</span>
          <span>{timeAgo}</span>
        </div>
        {/* Division bar in-flow on narrow screens */}
        <div className="md:hidden mt-1.5 max-w-48">
          <ConsensusBar
            percent={consensusPercent}
            label={consensusLabel}
            labelLow={consensusLowLabel}
            labelEmpty={consensusEmptyLabel}
            size="compact"
          />
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

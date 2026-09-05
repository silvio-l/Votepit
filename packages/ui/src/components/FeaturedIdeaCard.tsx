import type { ReactNode } from 'react'
import { cx } from '../lib/cx'
import { Badge } from './Badge'
import { ConsensusBar } from './ConsensusBar'
import type { Status } from './StatusBadge'
import { StatusBadge } from './StatusBadge'
import type { UserVote } from './VoteWidget'
import { VoteWidget } from './VoteWidget'

interface FeaturedIdeaCardProps {
  title: string
  description: ReactNode
  status: Status
  score: number
  commentCount: number
  consensusPercent: number | null
  /** Raw FOR / AGAINST counts — shown as "140 / 12", as on the ballot lines below. */
  upCount?: number
  downCount?: number
  /** Relative age ("3 days ago"), as on the ballot lines below. */
  timeAgo?: string
  weeklyVotes: number
  weeklyNewIdeas: number
  avgConsensusPercent: number
  userVote?: UserVote
  onVoteUp?: () => void
  onVoteDown?: () => void
  href?: string
  /** i18n overrides — all default to German so existing callers keep working untranslated. */
  statusLabel?: string
  topIdeaLabel?: string
  commentLabel?: string
  commentsLabel?: string
  thisWeekLabel?: string
  votesGivenLabel?: string
  newIdeasLabel?: string
  avgConsensusLabel?: string
  consensusLabel?: string
  consensusLowLabel?: string
  consensusEmptyLabel?: string
  /** Screen-reader words in front of the FOR / AGAINST counts. */
  forLabel?: string
  againstLabel?: string
  upAriaLabel?: string
  downAriaLabel?: string
  className?: string
}

function InkStat({ value, label }: { value: string | number; label: string }) {
  return (
    <div className="flex flex-col gap-0.5 min-w-0">
      <dd className="font-archivo font-extrabold text-vp-2xl md:text-vp-3xl leading-none tracking-[-0.03em] tabular-nums text-vp-on-ink order-1">
        {value}
      </dd>
      <dt className="text-vp-xs text-vp-on-ink-muted leading-4 order-2">{label}</dt>
    </div>
  )
}

/**
 * The declared result at the head of the sheet: the leading idea with its
 * ballot on the paper, and this week's figures on an ink plane in the
 * margin — the landing's grammar of a white plane meeting an ink field.
 * Renders no sheet of its own; the board's result sheet owns the outline
 * and rules this block against the ballot lines beneath it.
 */
export function FeaturedIdeaCard({
  title,
  description,
  status,
  score,
  commentCount,
  consensusPercent,
  upCount,
  downCount,
  timeAgo,
  weeklyVotes,
  weeklyNewIdeas,
  avgConsensusPercent,
  userVote,
  onVoteUp,
  onVoteDown,
  href,
  statusLabel,
  topIdeaLabel = 'Top idea',
  commentLabel = 'Comment',
  commentsLabel = 'Comments',
  thisWeekLabel = 'This week',
  votesGivenLabel = 'Votes cast',
  newIdeasLabel = 'new ideas',
  avgConsensusLabel = 'Avg. consensus',
  consensusLabel,
  consensusLowLabel,
  consensusEmptyLabel,
  forLabel = 'for',
  againstLabel = 'against',
  upAriaLabel,
  downAriaLabel,
  className,
}: FeaturedIdeaCardProps) {
  const contested = consensusPercent !== null && consensusPercent < 50
  const hasSplit = upCount !== undefined && downCount !== undefined
  const titleClass =
    'font-archivo font-bold text-vp-xl sm:text-vp-2xl text-vp-ink leading-[1.15] text-pretty tracking-[-0.025em]'

  return (
    <section className={cx('flex flex-col md:flex-row animate-vp-rise', className)}>
      <div className="relative flex-1 flex items-center gap-4 sm:gap-5 p-4 sm:p-6">
        <div className="relative z-10 shrink-0 self-start">
          <VoteWidget
            tone="leading"
            score={score}
            userVote={userVote}
            onVoteUp={onVoteUp}
            onVoteDown={onVoteDown}
            upAriaLabel={upAriaLabel}
            downAriaLabel={downAriaLabel}
          />
        </div>

        <div className="flex-1 min-w-0 flex flex-col gap-2.5">
          <div>
            <Badge tone="accent">{topIdeaLabel}</Badge>
          </div>
          {href ? (
            <h2>
              <a
                href={href}
                className={cx(
                  titleClass,
                  'no-underline decoration-2 underline-offset-[0.15em] decoration-vp-underline hover:underline',
                  "after:absolute after:inset-0 after:content-['']",
                )}
              >
                {title}
              </a>
            </h2>
          ) : (
            <h2 className={titleClass}>{title}</h2>
          )}
          <p className="text-vp-base text-vp-text-secondary leading-6 line-clamp-3">{description}</p>
          {/* Same meta line as the ballot lines below — status · split · comments
              · age — so the head of the sheet reads like the rows it heads. */}
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-vp-xs text-vp-text-muted">
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
            {timeAgo && (
              <>
                <span aria-hidden="true">·</span>
                <span>{timeAgo}</span>
              </>
            )}
          </div>
          <div className="w-48 pt-1">
            <ConsensusBar
              percent={consensusPercent}
              label={consensusLabel}
              labelLow={consensusLowLabel}
              labelEmpty={consensusEmptyLabel}
            />
          </div>
        </div>
      </div>

      {/* The margin column: an ink plane carrying the week's figures. Centred
          so the block reads as two balanced fields rather than a tall panel
          next to a short one. */}
      <div className="md:w-56 shrink-0 vp-ink-panel p-4 sm:p-5 flex flex-col justify-center gap-4">
        <h3 className="vp-eyebrow text-vp-on-ink-muted">{thisWeekLabel}</h3>
        <dl className="grid grid-cols-3 md:grid-cols-1 gap-4">
          <InkStat value={`+${weeklyVotes}`} label={votesGivenLabel} />
          <InkStat value={weeklyNewIdeas} label={newIdeasLabel} />
          <InkStat value={`${avgConsensusPercent}%`} label={avgConsensusLabel} />
        </dl>
      </div>
    </section>
  )
}

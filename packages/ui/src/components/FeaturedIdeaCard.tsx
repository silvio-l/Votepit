import { Badge } from './Badge'
import { ConsensusBar } from './ConsensusBar'
import { Stat } from './Stat'
import type { Status } from './StatusBadge'
import { StatusBadge } from './StatusBadge'
import type { UserVote } from './VoteWidget'
import { VoteWidget } from './VoteWidget'

interface FeaturedIdeaCardProps {
  title: string
  description: string
  status: Status
  score: number
  commentCount: number
  consensusPercent: number | null
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
  upAriaLabel?: string
  downAriaLabel?: string
}

/**
 * The declared result at the head of the sheet: the leading idea with its
 * ballot, and this week's figures in the margin column. Raised a step above
 * the list beneath it — the one card on the board that lifts.
 */
export function FeaturedIdeaCard({
  title,
  description,
  status,
  score,
  commentCount,
  consensusPercent,
  weeklyVotes,
  weeklyNewIdeas,
  avgConsensusPercent,
  userVote,
  onVoteUp,
  onVoteDown,
  href,
  statusLabel,
  topIdeaLabel = 'Top-Idee',
  commentLabel = 'Kommentar',
  commentsLabel = 'Kommentare',
  thisWeekLabel = 'Diese Woche',
  votesGivenLabel = 'Stimmen abgegeben',
  newIdeasLabel = 'neue Ideen',
  avgConsensusLabel = 'Ø Konsens',
  consensusLabel,
  consensusLowLabel,
  consensusEmptyLabel,
  upAriaLabel,
  downAriaLabel,
}: FeaturedIdeaCardProps) {
  const titleClass = 'text-vp-xl sm:text-vp-2xl font-semibold text-vp-ink leading-7 sm:leading-8 text-pretty tracking-[-0.015em]'

  return (
    <section className="vp-card vp-sheet--ruled flex flex-col md:flex-row overflow-hidden animate-vp-rise">
      <div className="relative flex-1 flex gap-4 sm:gap-5 p-4 sm:p-6">
        <div className="relative z-10 shrink-0">
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
            <Badge tone="success" shape="pill" dot>
              {topIdeaLabel}
            </Badge>
          </div>
          {href ? (
            <h2>
              <a
                href={href}
                className={`${titleClass} no-underline hover:text-vp-accent-strong transition-colors after:absolute after:inset-0 after:content-['']`}
              >
                {title}
              </a>
            </h2>
          ) : (
            <h2 className={titleClass}>{title}</h2>
          )}
          <p className="text-vp-base text-vp-text-secondary leading-6 line-clamp-3">{description}</p>
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 pt-1 text-vp-xs text-vp-text-muted">
            <StatusBadge status={status} label={statusLabel} variant="chip" />
            <span>
              {commentCount} {commentCount === 1 ? commentLabel : commentsLabel}
            </span>
            <div className="w-40">
              <ConsensusBar
                percent={consensusPercent}
                label={consensusLabel}
                labelLow={consensusLowLabel}
                labelEmpty={consensusEmptyLabel}
              />
            </div>
          </div>
        </div>
      </div>

      <div className="md:w-56 shrink-0 border-t md:border-t-0 md:border-l border-vp-border-subtle bg-vp-surface-frost p-4 sm:p-5">
        <h3 className="vp-eyebrow mb-3">{thisWeekLabel}</h3>
        <dl className="grid grid-cols-3 md:grid-cols-1 gap-4">
          <Stat value={`+${weeklyVotes}`} label={votesGivenLabel} />
          <Stat value={weeklyNewIdeas} label={newIdeasLabel} />
          <Stat value={`${avgConsensusPercent}%`} label={avgConsensusLabel} tone="up" />
        </dl>
      </div>
    </section>
  )
}

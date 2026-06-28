import { VoteWidget } from './VoteWidget'
import { ConsensusBar } from './ConsensusBar'
import { StatusBadge } from './StatusBadge'
import type { Status } from './StatusBadge'
import type { UserVote } from './VoteWidget'

interface FeaturedIdeaCardProps {
  title: string
  description: string
  status: Status
  score: number
  commentCount: number
  consensusPercent: number
  weeklyVotes: number
  weeklyNewIdeas: number
  avgConsensusPercent: number
  userVote?: UserVote
  onVoteUp?: () => void
  onVoteDown?: () => void
}

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
}: FeaturedIdeaCardProps) {
  return (
    <div className="flex gap-[14px]">
      {/* Left panel */}
      <div className="flex-1 bg-vp-surface border border-vp-border-subtle rounded-vp-xl shadow pl-6 pr-[26px] py-6">
        <div className="flex gap-[22px]">
          {/* VoteWidget leading */}
          <div className="shrink-0 self-start pt-1">
            <VoteWidget
              tone="leading"
              score={score}
              userVote={userVote}
              onVoteUp={onVoteUp}
              onVoteDown={onVoteDown}
            />
          </div>

          {/* Content */}
          <div className="flex-1 min-w-0 flex flex-col gap-3">
            {/* TOP-IDEE label */}
            <span className="text-[11px] font-bold font-inter text-vp-vote-up tracking-[1.32px] uppercase">
              Top-Idee
            </span>

            {/* Title */}
            <h2 className="text-[25px] font-semibold font-archivo text-vp-ink leading-[1.16]">
              {title}
            </h2>

            {/* Description */}
            <p className="text-[15px] text-vp-text-secondary leading-[1.48]">
              {description}
            </p>

            {/* Meta */}
            <div className="flex items-center gap-3 flex-wrap">
              <StatusBadge status={status} />
              <span className="text-[13px] text-vp-text-muted font-mono-num">
                {commentCount} Kommentare
              </span>
              <div className="flex-1 min-w-[120px]">
                <ConsensusBar percent={consensusPercent} />
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Right panel */}
      <div className="w-[300px] shrink-0 bg-vp-surface border border-vp-border-subtle rounded-vp-xl shadow p-[22px] flex flex-col gap-4">
        {/* DIESE WOCHE label */}
        <span className="text-[11px] font-bold text-vp-text-muted tracking-[1.1px] uppercase">
          Diese Woche
        </span>

        {/* Stat rows */}
        <div className="flex flex-col gap-3">
          {/* Weekly votes */}
          <div className="flex flex-col gap-0.5">
            <span className="text-[24px] font-mono-num font-bold text-vp-ink leading-none">
              {weeklyVotes}
            </span>
            <span className="text-[13px] text-vp-text-secondary">
              neue Stimmen
            </span>
          </div>

          {/* Weekly new ideas */}
          <div className="flex flex-col gap-0.5">
            <span className="text-[24px] font-mono-num font-bold text-vp-ink leading-none">
              {weeklyNewIdeas}
            </span>
            <span className="text-[13px] text-vp-text-secondary">
              neue Ideen
            </span>
          </div>

          {/* Average consensus */}
          <div className="flex flex-col gap-0.5">
            <span className="text-[24px] font-mono-num font-bold text-vp-vote-up leading-none">
              {avgConsensusPercent}%
            </span>
            <span className="text-[13px] text-vp-text-secondary">
              ⌀ Konsens
            </span>
          </div>
        </div>
      </div>
    </div>
  )
}

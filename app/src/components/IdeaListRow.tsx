import { ConsensusBar } from './ConsensusBar'
import type { Status } from './StatusBadge'
import { StatusBadge } from './StatusBadge'
import type { UserVote } from './VoteWidget'
import { VoteWidget } from './VoteWidget'

interface IdeaListRowProps {
  id: string | number
  title: string
  excerpt?: string
  status: Status
  score: number
  commentCount: number
  timeAgo: string
  consensusPercent: number
  userVote?: UserVote
  onVoteUp?: () => void
  onVoteDown?: () => void
  href?: string
}

export function IdeaListRow({
  title,
  excerpt,
  status,
  score,
  commentCount,
  timeAgo,
  consensusPercent,
  userVote,
  onVoteUp,
  onVoteDown,
  href,
}: IdeaListRowProps) {
  const inner = (
    <div
      className={[
        'flex items-center gap-5',
        'bg-vp-surface-frost border border-vp-border-subtle rounded-vp-lg',
        'pl-5 pr-6 py-[18px]',
        'shadow-[0px_8px_24px_-6px_rgba(20,23,26,0.07)]',
        href ? 'hover:border-vp-text-muted transition-colors duration-150' : '',
      ]
        .filter(Boolean)
        .join(' ')}
    >
      {/* Left: VoteWidget — stop click propagation so the row link doesn't fire.
          Not an interactive control itself; the VoteWidget buttons handle keyboard. */}
      {/* biome-ignore lint/a11y/useKeyWithClickEvents: click-guard wrapper, not a control */}
      {/* biome-ignore lint/a11y/noStaticElementInteractions: click-guard wrapper, not a control */}
      <div onClick={(e) => e.preventDefault()} className="shrink-0 self-start pt-1">
        <VoteWidget
          tone="neutral"
          score={score}
          userVote={userVote}
          onVoteUp={onVoteUp}
          onVoteDown={onVoteDown}
        />
      </div>

      {/* Middle: text content */}
      <div className="flex-1 min-w-0 flex flex-col gap-1">
        <p className="text-[17px] font-semibold font-archivo text-vp-ink leading-[1.22] line-clamp-2">
          {title}
        </p>
        {excerpt && (
          <p className="text-[14px] text-vp-text-secondary truncate leading-[1.5]">{excerpt}</p>
        )}
        {/* Meta row */}
        <div className="flex items-center gap-2 mt-0.5">
          <StatusBadge status={status} />
          <span className="text-[12px] text-vp-text-muted font-mono-num">{commentCount}</span>
          <span className="text-[12px] text-vp-text-muted">·</span>
          <span className="text-[12px] text-vp-text-muted">{timeAgo}</span>
        </div>
      </div>

      {/* Right: ConsensusBar */}
      <div className="w-40 shrink-0">
        <ConsensusBar percent={consensusPercent} />
      </div>

      {/* Arrow */}
      <span className="text-[22px] text-vp-text-muted font-medium shrink-0 leading-none">›</span>
    </div>
  )

  if (href) {
    return (
      <a href={href} className="block no-underline">
        {inner}
      </a>
    )
  }

  return inner
}

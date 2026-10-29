import type { ReactNode } from 'react'
import { cx } from '../lib/cx'
import { Avatar, avatarTintFor } from './Avatar'
import { type IdeaStatus, StatusChip, statusChipFor } from './StatusChip'
import { VoteControl, type VoteControlLabels, type VoteDirection } from './VoteControl'
import { VoteSummary, type VoteSummaryLabels } from './VoteSummaryView'

export interface IdeaRowProps {
  title: string
  /** Detail route. The title is the one link; the vote buttons are siblings, never nested. */
  href: string
  status: IdeaStatus
  /** Overrides the default status label (custom board status names). */
  statusLabel: string
  /** One line on desktop (clipped by CSS, full text stays in the DOM). */
  excerpt?: ReactNode
  /** Tag chips; rendered on the metadata line. */
  tags?: ReactNode
  up: number
  down: number
  commentCount: number
  /** Comment text (singular and plural). */
  commentLabel: (count: number) => string
  votedUp?: boolean
  votedDown?: boolean
  readonlyVotes?: boolean
  onVote?: (direction: VoteDirection) => Promise<void> | void
  voteLabels: VoteControlLabels
  summaryLabels: VoteSummaryLabels
  locale: string
  /** desktop: one dense line, compact votes. phone: stacked, large vote fields. */
  variant?: 'desktop' | 'phone'
  /** Badge for a pinned idea. Omitted when not given. */
  pinnedLabel?: string
  /** "<author> · <time>" line under the title (desktop) or in the status line (phone). */
  authorLine?: ReactNode
  /** Round author avatar before the author line: an image when `src` is given, else initials. */
  authorAvatar?: { name: string; src?: string | null }
  /** Compact translate toggle (icon button) next to the comment count. */
  translateControl?: ReactNode
  /** Stable data-testid on the row. The title link gets `<testId>-link`. */
  testId: string
}

/**
 * One idea in a dense board list (owner decision 2026-10-22, G-09). Status edge and chip,
 * title link, one-line excerpt, tags, separate For/Against buttons with absolute counts,
 * net and approval, and the comment count. Titles wrap on phones and clip on desktop.
 */
export function IdeaRow({
  title,
  href,
  status,
  statusLabel,
  excerpt,
  tags,
  up,
  down,
  commentCount,
  commentLabel,
  votedUp = false,
  votedDown = false,
  readonlyVotes = false,
  onVote,
  voteLabels,
  summaryLabels,
  locale,
  variant = 'desktop',
  pinnedLabel,
  authorLine,
  authorAvatar,
  translateControl,
  testId,
}: IdeaRowProps) {
  const authorNode = authorLine ? (
    <span className="ds-idea-row__author-line">
      {authorAvatar && (
        <Avatar
          size="small"
          name={authorAvatar.name}
          src={authorAvatar.src}
          tint={avatarTintFor(authorAvatar.name)}
        />
      )}
      <span>{authorLine}</span>
    </span>
  ) : null
  const chip = statusChipFor(status, statusLabel)
  const isPhone = variant === 'phone'
  const commentText = commentLabel(commentCount)
  const voteSize = isPhone ? 'field' : 'compact'
  const voteDisabled = readonlyVotes || !onVote

  const votes = (
    <div className="ds-idea-row__votes" data-testid={`${testId}-votes`}>
      <VoteControl
        direction="up"
        count={up}
        selected={votedUp}
        ideaTitle={title}
        size={voteSize}
        readonly={voteDisabled}
        labels={voteLabels}
        locale={locale}
        onVote={onVote ? () => onVote('up') : undefined}
        testId={`${testId}-vote-up`}
      />
      <VoteControl
        direction="down"
        count={down}
        selected={votedDown}
        ideaTitle={title}
        size={voteSize}
        readonly={voteDisabled}
        labels={voteLabels}
        locale={locale}
        onVote={onVote ? () => onVote('down') : undefined}
        testId={`${testId}-vote-down`}
      />
    </div>
  )

  const summary = (
    <VoteSummary
      up={up}
      down={down}
      variant="compact"
      meter={false}
      fields={['net', 'approval']}
      labels={summaryLabels}
      locale={locale}
      testId={`${testId}-summary`}
    />
  )

  const comments = (
    <span className="ds-idea-row__comments">
      <span data-testid={`${testId}-comments`}>{commentText}</span>
      {translateControl}
    </span>
  )

  return (
    <article
      className={cx('ds-idea-row', `ds-idea-row--${variant}`)}
      data-testid={testId}
      data-status={status}
      data-pinned={pinnedLabel ? "true" : undefined}
      data-variant={variant}
    >
      <div className="ds-idea-row__main">
        <div className="ds-idea-row__meta">
          <StatusChip tone={chip.tone} icon={chip.icon}>
            {chip.label}
          </StatusChip>
          {tags && <span className="ds-idea-row__tags">{tags}</span>}
          {pinnedLabel && <span className="ds-idea-row__pinned">{pinnedLabel}</span>}
          {isPhone && authorNode && <span className="ds-idea-row__author">{authorNode}</span>}
        </div>
        <h3 className="ds-idea-row__title" title={title}>
          <a href={href} data-testid={`${testId}-link`}>
            {title}
          </a>
        </h3>
        {!isPhone && authorNode && <div className="ds-idea-row__author">{authorNode}</div>}
        {excerpt && (
          <div
            className="ds-idea-row__excerpt"
            title={typeof excerpt === 'string' ? excerpt : undefined}
          >
            {excerpt}
          </div>
        )}
        {isPhone && (
          <div className="ds-idea-row__foot">
            {summary}
            {comments}
          </div>
        )}
      </div>
      {votes}
      {!isPhone && (
        <>
          <div className="ds-idea-row__summary">{summary}</div>
          <div className="ds-idea-row__comments-col">{comments}</div>
        </>
      )}
    </article>
  )
}

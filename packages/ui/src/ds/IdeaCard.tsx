import type { ReactNode } from 'react'
import { cx } from '../lib/cx'
import { Avatar } from './Avatar'
import { avatarTintFor } from './avatarTint'
import { CopyLinkButton } from './CopyLinkButton'
import { Icon } from './icons'
import { type IdeaStatus, StatusChip, statusChipFor } from './StatusChip'
import { VoteControl, type VoteControlLabels, type VoteDirection } from './VoteControl'
import { VoteSummary, type VoteSummaryLabels } from './VoteSummaryView'

export interface IdeaCardProps {
  title: string
  /** Short problem statement. Optional; long text wraps and never sets a fixed height. */
  problem?: ReactNode
  status: IdeaStatus
  /** Leading badge in the meta row, e.g. the featured-idea label. */
  badge?: ReactNode
  /** Overrides the default status label, e.g. a custom status name from the board. */
  statusLabel: string
  up: number
  down: number
  commentCount: number
  /** Caller-formatted update time, e.g. "vor 2 Tagen". */
  updatedLabel?: string
  /** Post-style author row above the title: avatar (tint from the name), name and time. Optional. */
  author?: { name: string; timeLabel?: string }
  /** 'field' = large tinted For/Against fields (card and detail). Default: inline pills. */
  voteStyle?: 'inline' | 'field'
  /** Copy-link action in the card's action row (social layout only). Omitted = no action row. */
  copyLink?: { value: string; label: string; copiedLabel: string }
  /** Extra class on the root (social layout only), e.g. to flush the card inside a shared container. */
  className?: string
  /** Detail route. The title is a real link so the detail stays linkable. */
  href: string
  /** Optional handler; the link's default is prevented when given (for in-page selection). */
  onOpen?: () => void
  variant?: 'list' | 'compact' | 'showcase'
  state?: 'default' | 'selected' | 'unread' | 'pending'
  /** Optional media. Without it, no empty image slot is rendered. */
  media?: ReactNode
  /** Committed vote state from the caller. */
  votedUp?: boolean
  votedDown?: boolean
  /** Read-only votes (no permission, closed board). */
  readonlyVotes?: boolean
  onVote?: (direction: VoteDirection) => Promise<void> | void
  /** BCP 47 locale for number formatting. */
  locale: string
  /** Visible texts of both vote controls. */
  voteLabels: VoteControlLabels
  /** Texts of the net and approval summary. */
  summaryLabels: VoteSummaryLabels
  /** Comment count text (singular and plural). */
  commentLabel: (count: number) => string
  /** Text for the pending state. */
  pendingLabel: string
  /** Text for the unread badge. */
  unreadLabel: string
  /** Badge text for a pinned idea. Omitted when not given. */
  pinnedLabel?: string
  /** Stable data-testid on the article. The title link gets `<testId>-link`. */
  testId: string
}

/**
 * Idea card for lists, compact lists and showcase placements. The title link and the
 * vote buttons are siblings, never nested buttons. Titles wrap; cards have no fixed height.
 */
export function IdeaCard({
  title,
  problem,
  status,
  statusLabel,
  up,
  down,
  commentCount,
  updatedLabel,
  href,
  onOpen,
  variant = 'list',
  state = 'default',
  media,
  votedUp = false,
  votedDown = false,
  readonlyVotes = false,
  onVote,
  locale,
  voteLabels,
  summaryLabels,
  commentLabel,
  pendingLabel,
  unreadLabel,
  pinnedLabel,
  badge,
  author,
  voteStyle = 'inline',
  copyLink,
  className,
  testId,
}: IdeaCardProps) {
  const chip = statusChipFor(status, statusLabel)
  const isSelected = state === 'selected'
  const voteSize = voteStyle === 'field' ? 'field' : variant === 'compact' ? 'compact' : 'inline'
  const votes = (
    <div className="ds-idea-card__votes" data-testid={`${testId}-votes`}>
      <VoteControl
        direction="up"
        count={up}
        selected={votedUp}
        ideaTitle={title}
        size={voteSize}
        readonly={readonlyVotes || !onVote}
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
        readonly={readonlyVotes || !onVote}
        labels={voteLabels}
        locale={locale}
        onVote={onVote ? () => onVote('down') : undefined}
        testId={`${testId}-vote-down`}
      />
    </div>
  )

  const commentText = commentLabel(commentCount)

  const body = (
    <div className="ds-idea-card__body">
      {media && <div className="ds-idea-card__media">{media}</div>}
      {author && (
        <div className="ds-idea-card__author" data-testid={`${testId}-author`}>
          <Avatar name={author.name} tint={avatarTintFor(author.name)} size="small" />
          <span className="ds-idea-card__author-name">{author.name}</span>
          {author.timeLabel && (
            <span className="ds-idea-card__author-time">{author.timeLabel}</span>
          )}
        </div>
      )}
      <h3 className="ds-idea-card__title">
        <a
          href={href}
          aria-current={isSelected ? 'true' : undefined}
          data-testid={`${testId}-link`}
          onClick={(event) => {
            if (!onOpen) return
            event.preventDefault()
            onOpen()
          }}
        >
          {title}
        </a>
      </h3>
      {problem && <div className="ds-idea-card__problem">{problem}</div>}
      <div className="ds-idea-card__meta">
        {badge && <span className="ds-idea-card__meta-item">{badge}</span>}
        <StatusChip tone={chip.tone} icon={chip.icon}>
          {chip.label}
        </StatusChip>
        <span className="ds-idea-card__meta-item">
          <VoteSummary
            up={up}
            down={down}
            variant="compact"
            meter
            fields={['net', 'approval']}
            labels={summaryLabels}
            locale={locale}
            testId={`${testId}-summary`}
          />
        </span>
        <span className="ds-idea-card__meta-item">
          <Icon name="comment" size="small" />
          <span data-testid={`${testId}-comments`}>{commentText}</span>
        </span>
        {updatedLabel && <span className="ds-idea-card__meta-item">{updatedLabel}</span>}
        {pinnedLabel && <span className="ds-idea-card__meta-item">{pinnedLabel}</span>}
        {state === 'unread' && <span className="ds-idea-card__unread">{unreadLabel}</span>}
        {state === 'pending' && (
          <span className="ds-idea-card__meta-item" role="status">
            {pendingLabel}
          </span>
        )}
      </div>
    </div>
  )

  // Social layout (owner decision 2026-10-22, codex/board-social S2): author and status on top,
  // large tinted For/Against fields, a summary panel and a quiet footer. Used for board cards.
  if (voteStyle === 'field') {
    return (
      <article
        className={cx(
          'ds-idea-card',
          'ds-idea-card--social',
          isSelected && 'ds-idea-card--selected',
          state === 'pending' && 'ds-idea-card--pending',
          className,
        )}
        data-testid={testId}
        data-state={state}
        data-status={status}
        aria-busy={state === 'pending' || undefined}
      >
        {media && <div className="ds-idea-card__media">{media}</div>}
        <div className="ds-idea-card__head">
          {author ? (
            <div className="ds-idea-card__author" data-testid={`${testId}-author`}>
              <Avatar name={author.name} tint={avatarTintFor(author.name)} size="small" />
              <span className="ds-idea-card__author-name">{author.name}</span>
              {author.timeLabel && (
                <span className="ds-idea-card__author-time">{author.timeLabel}</span>
              )}
            </div>
          ) : (
            <span className="ds-idea-card__head-spacer" aria-hidden="true" />
          )}
          <StatusChip tone={chip.tone} icon={chip.icon}>
            {chip.label}
          </StatusChip>
        </div>
        <h3 className="ds-idea-card__title">
          <a
            href={href}
            aria-current={isSelected ? 'true' : undefined}
            data-testid={`${testId}-link`}
            onClick={(event) => {
              if (!onOpen) return
              event.preventDefault()
              onOpen()
            }}
          >
            {title}
          </a>
        </h3>
        {problem && <div className="ds-idea-card__problem">{problem}</div>}
        {votes}
        <div className="ds-idea-card__foot">
          <VoteSummary
            up={up}
            down={down}
            variant="compact"
            meter
            fields={['net', 'approval']}
            labels={summaryLabels}
            locale={locale}
            testId={`${testId}-summary`}
          />
          <div className="ds-idea-card__actions">
            <span className="ds-idea-card__meta-item">
              <Icon name="comment" size="small" />
              <span data-testid={`${testId}-comments`}>{commentText}</span>
            </span>
            {copyLink && (
              <CopyLinkButton
                value={copyLink.value}
                label={copyLink.label}
                copiedLabel={copyLink.copiedLabel}
                showLabel
                testId={`${testId}-copy-link`}
              />
            )}
          </div>
          <div className="ds-idea-card__meta">
            {badge && <span className="ds-idea-card__meta-item">{badge}</span>}
            {updatedLabel && <span className="ds-idea-card__meta-item">{updatedLabel}</span>}
            {pinnedLabel && <span className="ds-idea-card__meta-item">{pinnedLabel}</span>}
            {state === 'unread' && <span className="ds-idea-card__unread">{unreadLabel}</span>}
            {state === 'pending' && (
              <span className="ds-idea-card__meta-item" role="status">
                {pendingLabel}
              </span>
            )}
          </div>
        </div>
      </article>
    )
  }

  return (
    <article
      className={cx(
        'ds-idea-card',
        `ds-idea-card--${variant}`,
        isSelected && 'ds-idea-card--selected',
        state === 'pending' && 'ds-idea-card--pending',
      )}
      data-testid={testId}
      data-state={state}
      data-status={status}
      aria-busy={state === 'pending' || undefined}
    >
      {variant === 'showcase' ? (
        <>
          {body}
          {votes}
        </>
      ) : (
        <>
          {votes}
          {body}
        </>
      )}
    </article>
  )
}

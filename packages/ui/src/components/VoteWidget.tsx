import { useEffect, useRef, useState } from 'react'
import { cx } from '../lib/cx'

export type VoteTone = 'leading' | 'neutral'
export type UserVote = 'up' | 'down' | null

// ── Ballot marks ─────────────────────────────────────────────────────────────
function CheckMark({ size }: { size: number }) {
  return (
    <svg
      viewBox="0 0 16 16"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="2.25"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M3 8.5l3.2 3.2L13 5" />
    </svg>
  )
}

function CrossMark({ size }: { size: number }) {
  return (
    <svg
      viewBox="0 0 16 16"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="2.25"
      strokeLinecap="round"
      aria-hidden="true"
    >
      <path d="M4 4l8 8M12 4l-8 8" />
    </svg>
  )
}

// ── VoteWidget ───────────────────────────────────────────────────────────────
interface VoteWidgetProps {
  tone?: VoteTone
  score: number
  userVote?: UserVote
  onVoteUp?: () => void
  onVoteDown?: () => void
  disabled?: boolean
  upAriaLabel?: string
  downAriaLabel?: string
  /**
   * Visible short labels under the two marks ("For" / "Against"). The
   * accessible names above must contain these words (WCAG 2.5.3).
   */
  upLabel?: string
  downLabel?: string
}

/**
 * The ballot: a FOR box above the tally, an AGAINST box below it. Marking a
 * box stamps it in the vote colour with its mark (check / cross) so a real
 * down-vote never reads as "un-like". Buttons carry aria-pressed; the tally
 * is tabular mono and pops once when it changes. The stamp and the tally pop
 * are the whole confirmation — nothing floats above the sheet.
 */
export function VoteWidget({
  tone = 'neutral',
  score,
  userVote,
  onVoteUp,
  onVoteDown,
  disabled = false,
  upAriaLabel = 'Upvote',
  downAriaLabel = 'Downvote',
  upLabel,
  downLabel,
}: VoteWidgetProps) {
  const [pop, setPop] = useState(0)
  const prevScoreRef = useRef(score)

  // Replay the tally stamp only when the score actually changes (not on mount).
  useEffect(() => {
    if (prevScoreRef.current !== score) {
      prevScoreRef.current = score
      setPop((k) => k + 1)
    }
  }, [score])

  const leading = tone === 'leading'
  const boxSize = leading ? 'size-11' : 'size-9'
  const markSize = leading ? 18 : 15

  const boxBase = cx(
    'flex items-center justify-center rounded-vp-md border-[1.5px] cursor-pointer vp-press',
    'shadow-[0_1px_2px_rgba(21,22,26,0.05)]',
    'disabled:opacity-50 disabled:cursor-not-allowed',
    boxSize,
  )
  const upClass =
    userVote === 'up'
      ? 'bg-vp-vote-up border-vp-vote-up text-white shadow-[0_2px_8px_-2px_rgba(14,148,102,0.6)]'
      : leading
        ? 'bg-vp-surface border-vp-vote-up text-vp-vote-up-strong hover:bg-vp-vote-up-soft'
        : 'bg-vp-surface border-vp-border-strong text-vp-text-secondary hover:border-vp-vote-up hover:text-vp-vote-up-strong hover:bg-vp-vote-up-soft'
  const downClass =
    userVote === 'down'
      ? 'bg-vp-vote-down border-vp-vote-down text-white shadow-[0_2px_8px_-2px_rgba(216,80,60,0.6)]'
      : leading
        ? 'bg-vp-surface border-vp-vote-down text-vp-vote-down-strong hover:bg-vp-vote-down-soft'
        : 'bg-vp-surface border-vp-border-strong text-vp-text-secondary hover:border-vp-vote-down hover:text-vp-vote-down-strong hover:bg-vp-vote-down-soft'

  return (
    <div className={cx('relative flex flex-col items-center gap-1', leading ? 'w-14' : 'w-10')}>
      <button
        type="button"
        aria-label={upAriaLabel}
        aria-pressed={userVote === 'up'}
        onClick={disabled ? undefined : onVoteUp}
        disabled={disabled}
        className={cx(boxBase, upClass)}
      >
        <span key={userVote === 'up' ? 'on' : 'off'} className={cx('inline-flex', userVote === 'up' && 'animate-vp-stamp')}>
          <CheckMark size={markSize} />
        </span>
      </button>
      {upLabel && <span className="text-vp-2xs text-vp-text-secondary leading-none">{upLabel}</span>}

      {/* Tally */}
      <span
        key={pop}
        className={cx(
          'font-mono-num font-bold leading-none tabular-nums py-1 tracking-tight',
          leading ? 'text-vp-xl' : 'text-vp-md',
          userVote === 'up'
            ? 'text-vp-vote-up-strong'
            : userVote === 'down'
              ? 'text-vp-vote-down-strong'
              : 'text-vp-ink',
          pop > 0 && 'animate-vp-stamp',
        )}
      >
        {score}
      </span>

      <button
        type="button"
        aria-label={downAriaLabel}
        aria-pressed={userVote === 'down'}
        onClick={disabled ? undefined : onVoteDown}
        disabled={disabled}
        className={cx(boxBase, downClass)}
      >
        <span
          key={userVote === 'down' ? 'on' : 'off'}
          className={cx('inline-flex', userVote === 'down' && 'animate-vp-stamp')}
        >
          <CrossMark size={markSize} />
        </span>
      </button>
      {downLabel && (
        <span className="text-vp-2xs text-vp-text-secondary leading-none">{downLabel}</span>
      )}
    </div>
  )
}

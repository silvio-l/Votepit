import { motion, AnimatePresence, useReducedMotion } from 'framer-motion'

export type VoteTone = 'leading' | 'neutral'
export type UserVote = 'up' | 'down' | null

interface VoteWidgetProps {
  tone?: VoteTone
  score: number
  userVote?: UserVote
  onVoteUp?: () => void
  onVoteDown?: () => void
  disabled?: boolean
}

function UpChevron() {
  return (
    <svg width="11" height="7" viewBox="0 0 11 7" fill="none" aria-hidden="true">
      <path
        d="M1 6L5.5 1L10 6"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

function DownChevron() {
  return (
    <svg
      width="11"
      height="7"
      viewBox="0 0 11 7"
      fill="none"
      aria-hidden="true"
      style={{ transform: 'rotate(180deg)' }}
    >
      <path
        d="M1 6L5.5 1L10 6"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

export function VoteWidget({
  tone = 'neutral',
  score,
  userVote,
  onVoteUp,
  onVoteDown,
  disabled = false,
}: VoteWidgetProps) {
  const reduceMotion = useReducedMotion()

  const upBtnClass =
    tone === 'leading'
      ? 'bg-vp-vote-up text-vp-on-ink shadow-[0px_3px_13px_0px_rgba(14,148,102,0.35)]'
      : 'bg-vp-surface border border-vp-border-subtle text-vp-text-muted'

  const downBtnClass =
    'bg-vp-surface border border-vp-border-subtle text-vp-text-muted'

  return (
    <div className="flex flex-col items-center gap-1 w-[54px]">
      {/* Up button */}
      <motion.button
        aria-label="Upvote"
        aria-pressed={userVote === 'up'}
        onClick={disabled ? undefined : onVoteUp}
        disabled={disabled}
        whileTap={reduceMotion || disabled ? undefined : { scale: 0.9 }}
        className={[
          'w-full flex items-center justify-center',
          'py-2 rounded-vp-md',
          'cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed',
          'transition-opacity duration-150',
          upBtnClass,
        ].join(' ')}
      >
        <UpChevron />
      </motion.button>

      {/* Score */}
      <div className="relative h-8 flex items-center justify-center w-full overflow-hidden">
        <AnimatePresence mode="popLayout" initial={false}>
          <motion.span
            key={score}
            initial={reduceMotion ? false : { y: -8, opacity: 0 }}
            animate={{ y: 0, opacity: 1 }}
            exit={reduceMotion ? undefined : { y: 8, opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="font-mono-num text-[22px] font-bold text-vp-ink leading-none absolute"
          >
            {score}
          </motion.span>
        </AnimatePresence>
      </div>

      {/* Down button */}
      <motion.button
        aria-label="Downvote"
        aria-pressed={userVote === 'down'}
        onClick={disabled ? undefined : onVoteDown}
        disabled={disabled}
        whileTap={reduceMotion || disabled ? undefined : { scale: 0.9 }}
        className={[
          'w-full flex items-center justify-center',
          'py-2 rounded-vp-md',
          'cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed',
          'transition-opacity duration-150',
          downBtnClass,
        ].join(' ')}
      >
        <DownChevron />
      </motion.button>
    </div>
  )
}

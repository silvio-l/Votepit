import { AnimatePresence, motion, useReducedMotion } from 'framer-motion'
import { useCallback, useEffect, useRef, useState } from 'react'

export type VoteTone = 'leading' | 'neutral'
export type UserVote = 'up' | 'down' | null

// ── Toast slogans (DE, fixed plaintext list — no XSS risk) ──────────────────
const TOASTS_UP = [
  'Mutige Wahl.',
  'Notiert — die Menge tobt.',
  '+1 für die Roadmap.',
  'Der Pit ist dafür.',
  "Stark, wenn's stimmt.",
  'Kommt bald™',
] as const

const TOASTS_DOWN = [
  'Ehrlich. Respekt.',
  'Zur Kenntnis genommen.',
  'Harte Menge.',
  'Der Pit hat gesprochen.',
  'Brutal, aber fair.',
] as const

const JACKPOT = 'Absoluter Banger'
const JACKPOT_PROB = 0.14

function pickRandom(arr: readonly string[], last: string): string {
  if (arr.length < 2) return arr[0] ?? ''
  let pick: string
  do {
    pick = arr[Math.floor(Math.random() * arr.length)] ?? ''
  } while (pick === last)
  return pick
}

// ── Particle burst ───────────────────────────────────────────────────────────
// 6 dots fly outward from the clicked button centre (token-coloured).
// Colours reference semantic CSS vars — never change per board.
const BURST_ANGLES = [0, 60, 120, 180, 240, 300]
const BURST_DIST_PX = 26

function ParticleBurst({ colorVar }: { colorVar: string }) {
  return (
    <>
      {BURST_ANGLES.map((angle) => {
        const rad = (angle * Math.PI) / 180
        const tx = Math.round(Math.cos(rad) * BURST_DIST_PX)
        const ty = Math.round(Math.sin(rad) * BURST_DIST_PX)
        return (
          <motion.span
            key={angle}
            initial={{ x: 0, y: 0, opacity: 0.85, scale: 1 }}
            animate={{ x: tx, y: ty, opacity: 0, scale: 0.4 }}
            transition={{ duration: 0.42, ease: [0.2, 0.7, 0.3, 1] }}
            aria-hidden="true"
            style={{
              position: 'absolute',
              top: '50%',
              left: '50%',
              width: 7,
              height: 7,
              borderRadius: '50%',
              backgroundColor: colorVar,
              marginTop: -3.5,
              marginLeft: -3.5,
              pointerEvents: 'none',
              zIndex: 10,
            }}
          />
        )
      })}
    </>
  )
}

// ── SVG icons ────────────────────────────────────────────────────────────────
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

// ── VoteWidget ───────────────────────────────────────────────────────────────
interface VoteWidgetProps {
  tone?: VoteTone
  score: number
  userVote?: UserVote
  onVoteUp?: () => void
  onVoteDown?: () => void
  disabled?: boolean
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

  const [toast, setToast] = useState<{ msg: string; key: number } | null>(null)
  const [upBurstKey, setUpBurstKey] = useState<number | null>(null)
  const [downBurstKey, setDownBurstKey] = useState<number | null>(null)

  const lastMsgRef = useRef('')
  const jackpotShownRef = useRef(false)
  const toastTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Cleanup timer on unmount
  useEffect(() => {
    return () => {
      if (toastTimerRef.current) clearTimeout(toastTimerRef.current)
    }
  }, [])

  const showToast = useCallback((msg: string) => {
    if (toastTimerRef.current) clearTimeout(toastTimerRef.current)
    setToast((prev) => ({ msg, key: (prev?.key ?? 0) + 1 }))
    toastTimerRef.current = setTimeout(() => setToast(null), 1900)
  }, [])

  const handleVoteUp = useCallback(() => {
    onVoteUp?.()
    if (reduceMotion) return
    setUpBurstKey((k) => (k ?? 0) + 1)
    let msg: string
    if (!jackpotShownRef.current && Math.random() < JACKPOT_PROB) {
      jackpotShownRef.current = true
      msg = JACKPOT
    } else {
      msg = pickRandom(TOASTS_UP, lastMsgRef.current)
    }
    lastMsgRef.current = msg
    showToast(msg)
  }, [onVoteUp, reduceMotion, showToast])

  const handleVoteDown = useCallback(() => {
    onVoteDown?.()
    if (reduceMotion) return
    setDownBurstKey((k) => (k ?? 0) + 1)
    const msg = pickRandom(TOASTS_DOWN, lastMsgRef.current)
    lastMsgRef.current = msg
    showToast(msg)
  }, [onVoteDown, reduceMotion, showToast])

  const upBtnClass =
    tone === 'leading'
      ? 'bg-vp-vote-up text-vp-on-ink shadow-[0px_3px_13px_0px_rgba(14,148,102,0.35)]'
      : 'bg-vp-surface border border-vp-border-subtle text-vp-text-muted'

  const downBtnClass = 'bg-vp-surface border border-vp-border-subtle text-vp-text-muted'

  return (
    <div className="relative flex flex-col items-center gap-1 w-[54px]">
      {/* Toast pill — centred above widget, token-coloured (brand primary / teal) */}
      <AnimatePresence>
        {toast && (
          <motion.span
            key={toast.key}
            initial={{ opacity: 0, y: 4, scale: 0.92 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -4, scale: 0.94 }}
            transition={{ duration: 0.18, ease: 'easeOut' }}
            aria-hidden="true"
            className="absolute -top-8 left-1/2 -translate-x-1/2 whitespace-nowrap pointer-events-none z-20 px-2.5 py-0.5 rounded-full text-[0.68rem] font-semibold font-inter text-white shadow-sm"
            style={{ background: 'var(--vp-primary)' }}
          >
            {toast.msg}
          </motion.span>
        )}
      </AnimatePresence>

      {/* Up button + particle burst */}
      <div className="relative w-full">
        <motion.button
          aria-label="Upvote"
          aria-pressed={userVote === 'up'}
          onClick={disabled ? undefined : handleVoteUp}
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
        {/* Mounted fresh each vote (key change) → runs initial→animate from scratch */}
        {upBurstKey !== null && (
          <ParticleBurst key={upBurstKey} colorVar="var(--color-vp-vote-up)" />
        )}
      </div>

      {/* Score — slide-in + brief scale pop on change */}
      <div className="relative h-8 flex items-center justify-center w-full overflow-hidden">
        <AnimatePresence mode="popLayout" initial={false}>
          <motion.span
            key={score}
            initial={reduceMotion ? false : { y: -8, opacity: 0 }}
            animate={reduceMotion ? { y: 0, opacity: 1 } : { y: 0, opacity: 1, scale: [1.22, 1] }}
            exit={reduceMotion ? undefined : { y: 8, opacity: 0 }}
            transition={{ duration: 0.24, ease: 'easeOut' }}
            className="font-mono-num text-[22px] font-bold text-vp-ink leading-none absolute"
          >
            {score}
          </motion.span>
        </AnimatePresence>
      </div>

      {/* Down button + particle burst */}
      <div className="relative w-full">
        <motion.button
          aria-label="Downvote"
          aria-pressed={userVote === 'down'}
          onClick={disabled ? undefined : handleVoteDown}
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
        {downBurstKey !== null && (
          <ParticleBurst key={downBurstKey} colorVar="var(--color-vp-vote-down)" />
        )}
      </div>
    </div>
  )
}

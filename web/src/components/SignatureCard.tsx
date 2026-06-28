import { useState, useCallback, useRef, useEffect } from 'react'
import { motion, AnimatePresence } from 'framer-motion'

interface Particle {
  id: number
  x: number
  y: number
  emoji?: string
  color?: string
  round?: boolean
  dx: number
  dy: number
  rot: number
  createdAt: number
}

const toastUp = ['Awesome!', 'Popular!', '+1', 'Great idea!']
const toastDown = ['Not sure...', 'Disagree', '-1', 'Hmm']
const jackpot = 'Team favorite!'

function fmt(n: number) {
  return (n > 0 ? '+' : '') + n
}

function pick(arr: string[], last: string) {
  if (arr.length < 2) return arr[0]
  let m: string
  do { m = arr[Math.floor(Math.random() * arr.length)] } while (m === last)
  return m
}

export default function SignatureCard({
  t,
}: {
  t: {
    signature: {
      upLabel: string
      downLabel: string
      hint: string
      eyebrow: string
      idea: string
      consensusValue: string
      consensusLabel: string
      consensusLabelLow: string
      status: string
      caption: string
    }
  }
}) {
  const s = t.signature
  const [upCount, setUpCount] = useState(140)
  const [downCount, setDownCount] = useState(12)
  const [dir, setDir] = useState<1 | -1>(1)
  const [lastToast, setLastToast] = useState('')
  const [toastMsg, setToastMsg] = useState('')
  const [particles, setParticles] = useState<Particle[]>([])
  const [interacted, setInteracted] = useState(false)
  const nextParticleId = useRef(0)

  const score = upCount - downCount
  const total = upCount + downCount
  const pct = total > 0 ? Math.round((upCount / total) * 100) : 0
  const low = pct < 50

  useEffect(() => {
    const timer = setTimeout(() => {
      if (!interacted) setDir(1) // hint animation trigger
    }, 1400)
    return () => clearTimeout(timer)
  }, [interacted])

  useEffect(() => {
    const now = Date.now()
    setParticles((prev) => prev.filter((p) => now - p.createdAt < 1200))
  }, [particles.length])

  const vote = useCallback(
    (d: 1 | -1, event: React.MouseEvent) => {
      setInteracted(true)
      setDir(d)

      // Cookieless Matomo engagement event (Matomo-Goal "Demo-Vote ausprobiert",
      // idSite=6). Guarded — fires sobald der Matomo-Snippet _paq bereitstellt.
      const paq = (window as unknown as { _paq?: unknown[][] })._paq
      if (paq) paq.push(['trackEvent', 'Engagement', 'Demo-Vote ausprobiert', d === 1 ? 'up' : 'down'])

      if (d === 1) {
        setUpCount((c) => c + 1)
      } else {
        setDownCount((c) => c + 1)
      }

      const el = event.currentTarget as HTMLElement
      const card = el.closest('.vp-sigcard') as HTMLElement
      const rect = el.getBoundingClientRect()
      const cr = card.getBoundingClientRect()
      const cx = rect.left - cr.left + rect.width / 2
      const cy = rect.top - cr.top + rect.height / 2
      const isJackpot = d === 1 && Math.random() < 0.14

      if (isJackpot) {
        setToastMsg(jackpot)
        setLastToast(jackpot)
      } else {
        const msgs = d === 1 ? toastUp : toastDown
        const msg = pick(msgs, lastToast)
        setLastToast(msg)
        setToastMsg(msg)
      }

      const emojis = isJackpot
        ? ['🎉', '🚀', '🔥', '🏆', '⭐', '💥']
        : ['🎉', '✨', '👏', '💚']
      const n = isJackpot ? 18 : 11
      const color = d === 1 ? '#0E9466' : '#D8503C'
      const big = isJackpot

      const newParticles: Particle[] = []
      for (let i = 0; i < n; i++) {
        const useEmoji = Math.random() < (big ? 0.7 : 0.45)
        newParticles.push({
          id: nextParticleId.current++,
          x: cx,
          y: cy,
          emoji: useEmoji ? emojis[Math.floor(Math.random() * emojis.length)] : undefined,
          color: useEmoji ? undefined : color,
          round: useEmoji ? undefined : Math.random() < 0.5,
          dx: (Math.random() * 2 - 1) * (big ? 130 : 90),
          dy: -(30 + Math.random() * (big ? 150 : 90)),
          rot: Math.random() * 360 - 180,
          createdAt: Date.now(),
        })
      }
      setParticles((prev) => [...prev, ...newParticles].slice(-80))
    },
    [lastToast],
  )

  return (
    <figure className="max-w-[26rem] mx-auto mt-12 sm:mt-16 md:mt-[4.5rem]">
      <div
        className="vp-sigcard relative flex items-center gap-[1.1rem] text-left p-[1.1rem_1.35rem] bg-white/72 border border-white/55 rounded-[16px] shadow-[0_0_0_1px_rgba(21,22,26,0.1),0_10px_30px_-12px_rgba(21,22,26,0.12)] backdrop-blur-[14px] saturate-[1.2]"
      >
        {/* Vote Controls */}
        <div className="flex flex-col items-center gap-[.4rem] shrink-0">
          <motion.button
            type="button"
            className={`vp-vbtn vp-vbtn--up flex items-center justify-center w-[3.1rem] h-[2.05rem] rounded-[12px] text-[.8rem] border ${
              dir === 1
                ? 'bg-[#0E9466] border-transparent text-white shadow-[0_7px_16px_-8px_#0E9466]'
                : 'bg-[#f1f1ee] border-[rgba(21,22,26,0.1)] text-[#979BA3]'
            } cursor-pointer`}
            whileTap={{ scale: 0.92 }}
            whileHover={dir !== 1 ? { y: -1 } : {}}
            onClick={(e) => vote(1, e)}
            aria-label={s.upLabel}
            title={s.hint}
          >
            ▲
          </motion.button>
          <span
            className={`font-mono font-semibold text-[1.35rem] tabular-nums leading-none transition-colors ${
              score < 0 ? 'text-[#D8503C]' : 'text-[#15161A]'
            }`}
          >
            {fmt(score)}
          </span>
          <motion.button
            type="button"
            className={`vp-vbtn vp-vbtn--down flex items-center justify-center w-[3.1rem] h-[2.05rem] rounded-[12px] text-[.8rem] border ${
              dir === -1
                ? 'bg-[#D8503C] border-transparent text-white shadow-[0_7px_16px_-8px_#D8503C]'
                : 'bg-[#f1f1ee] border-[rgba(21,22,26,0.1)] text-[#979BA3]'
            } cursor-pointer`}
            whileTap={{ scale: 0.92 }}
            whileHover={dir !== -1 ? { y: -1 } : {}}
            onClick={(e) => vote(-1, e)}
            aria-label={s.downLabel}
          >
            ▼
          </motion.button>
        </div>

        {/* Content */}
        <div className="min-w-0 flex-1">
          <p className="mb-[.3rem] font-semibold text-[.68rem] tracking-[.08em] uppercase text-[#0E9466]">
            {s.eyebrow}
          </p>
          <p className="mb-[.55rem] font-archivo font-extrabold text-[1.05rem] text-[#15161A] -tracking-[.01em]">
            {s.idea}
          </p>
          {/* Consensus Bar */}
          <div className="flex items-center gap-[.6rem]">
            <span
              className={`flex-1 h-[6px] rounded-full overflow-hidden transition-colors ${
                low ? 'bg-[#fee2e2]' : 'bg-[#dcfce7]'
              }`}
            >
              <motion.span
                className={`block h-full rounded-full ${
                  low ? 'bg-[#D8503C]' : 'bg-[#0E9466]'
                }`}
                animate={{ width: `${pct}%` }}
                transition={{ duration: 0.45, ease: [0.2, 0.7, 0.3, 1] }}
              />
            </span>
            <span className={`shrink-0 text-[.8rem] font-mono ${low ? 'text-[#D8503C]' : 'text-[#0E9466]'}`}>
              <strong className="font-semibold">{pct}%</strong> {low ? s.consensusLabelLow : s.consensusLabel}
            </span>
          </div>
          {/* Status */}
          <p className="mt-[.6rem] flex items-center gap-[.4rem] text-[.78rem] text-[#979BA3]">
            <span className="w-[7px] h-[7px] rounded-full bg-[#C0821A] shrink-0" />
            {s.status}
          </p>
        </div>

        {/* Toast */}
        <AnimatePresence>
          {toastMsg && (
            <motion.span
              key={toastMsg + Date.now()}
              className="absolute -top-[.7rem] right-[.9rem] px-[.6rem] py-[.25rem] rounded-full bg-[#15161A] text-white text-[.72rem] font-semibold whitespace-nowrap shadow-[0_6px_18px_-8px_rgba(21,22,26,0.12)]"
              initial={{ opacity: 0, y: 5, scale: 0.96 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: -5 }}
              transition={{ duration: 0.3 }}
              onAnimationComplete={() => setToastMsg('')}
            >
              {toastMsg}
            </motion.span>
          )}
        </AnimatePresence>

        {/* Particles */}
        <AnimatePresence>
          {particles.map((p) => (
            <motion.span
              key={p.id}
              className="vp-particle absolute w-[9px] h-[9px] text-[14px] leading-none pointer-events-none z-10"
              style={{
                left: p.x,
                top: p.y,
                backgroundColor: p.emoji ? undefined : p.color,
                borderRadius: p.emoji ? undefined : p.round ? '50%' : '2px',
              }}
              initial={{ opacity: 1, x: 0, y: 0, scale: 1, rotate: 0 }}
              animate={{
                opacity: 0,
                x: p.dx,
                y: p.dy,
                scale: 0.5,
                rotate: p.rot,
              }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.75 + Math.random() * 0.45, ease: [0.15, 0.7, 0.3, 1] }}
            >
              {p.emoji}
            </motion.span>
          ))}
        </AnimatePresence>

        {/* Screen reader announcement */}
        <span className="sr-only" aria-live="polite">
          Score: {score}
        </span>
      </div>
      <figcaption className="mt-4 text-center text-[.85rem] text-[#979BA3] leading-relaxed max-w-[24rem] mx-auto">
        {s.caption}
      </figcaption>
    </figure>
  )
}

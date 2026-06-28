import type { UserVote } from '@votepit/ui'
import { ConsensusBar, VoteWidget } from '@votepit/ui'
import { useCallback, useRef, useState } from 'react'

// Matomo: idSite=6 goal, guarded — fires once _paq exists
declare const window: Window & { _paq?: unknown[][] }

interface SignatureProps {
  eyebrow: string
  idea: string
  status: string
  caption: string
  toastsUp: readonly string[]
  toastsDown: readonly string[]
  jackpot: string
  announce: string
}

interface Props {
  t: {
    signature: SignatureProps
  }
}

const INITIAL_UP = 140
const INITIAL_DOWN = 12

function pickRandom(arr: readonly string[], last: string): string {
  if (arr.length < 2) return arr[0] ?? ''
  let pick: string
  do {
    pick = arr[Math.floor(Math.random() * arr.length)] ?? ''
  } while (pick === last)
  return pick
}

export default function SignatureCard({ t }: Props) {
  const s = t.signature

  const [upCount, setUpCount] = useState(INITIAL_UP)
  const [downCount, setDownCount] = useState(INITIAL_DOWN)
  const [userVote, setUserVote] = useState<UserVote>('up')
  const [toast, setToast] = useState<{ msg: string; key: number } | null>(null)

  const lastMsgRef = useRef('')
  const jackpotShownRef = useRef(false)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const score = upCount - downCount
  const total = upCount + downCount
  const percent = total > 0 ? Math.round((upCount / total) * 100) : 0

  const showToast = useCallback((msg: string) => {
    if (timerRef.current) clearTimeout(timerRef.current)
    setToast((prev) => ({ msg, key: (prev?.key ?? 0) + 1 }))
    timerRef.current = setTimeout(() => setToast(null), 1900)
  }, [])

  const handleVote = useCallback(
    (dir: 'up' | 'down') => {
      setUserVote(dir)
      if (dir === 'up') {
        setUpCount((c) => c + 1)
        if (window._paq)
          window._paq.push(['trackEvent', 'Engagement', 'Demo-Vote ausprobiert', 'up'])
        if (!jackpotShownRef.current && Math.random() < 0.14) {
          jackpotShownRef.current = true
          showToast(s.jackpot)
        } else {
          const msg = pickRandom(s.toastsUp, lastMsgRef.current)
          lastMsgRef.current = msg
          showToast(msg)
        }
      } else {
        setDownCount((c) => c + 1)
        if (window._paq)
          window._paq.push(['trackEvent', 'Engagement', 'Demo-Vote ausprobiert', 'down'])
        const msg = pickRandom(s.toastsDown, lastMsgRef.current)
        lastMsgRef.current = msg
        showToast(msg)
      }
    },
    [s, showToast],
  )

  return (
    <figure className="vp-signature vp-reveal vp-reveal-4">
      <div className="vp-sigcard">
        <VoteWidget
          score={score}
          userVote={userVote}
          tone={userVote === 'up' ? 'leading' : 'neutral'}
          onVoteUp={() => handleVote('up')}
          onVoteDown={() => handleVote('down')}
        />

        <div className="vp-sigbody">
          <p className="vp-eyebrow">{s.eyebrow}</p>
          <p className="vp-sigtitle">{s.idea}</p>
          <ConsensusBar percent={percent} />
          <p className="vp-status">
            <span className="vp-dot vp-dot--progress" />
            {s.status}
          </p>
        </div>

        {toast && (
          <span key={toast.key} className="vp-toast is-on" aria-hidden="true">
            {toast.msg}
          </span>
        )}

        <span className="vp-sr" aria-live="polite">
          {s.announce} {score}.
        </span>
      </div>
      <figcaption className="vp-sigcaption">{s.caption}</figcaption>
    </figure>
  )
}

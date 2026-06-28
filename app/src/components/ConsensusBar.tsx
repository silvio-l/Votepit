import { motion, useReducedMotion } from 'framer-motion'

interface ConsensusBarProps {
  percent: number
}

export function ConsensusBar({ percent }: ConsensusBarProps) {
  const reduceMotion = useReducedMotion()
  const clamped = Math.max(0, Math.min(100, percent))
  const isStrong = clamped >= 50

  const fillClass = isStrong ? 'bg-vp-consensus-strong' : 'bg-vp-vote-down'
  const label = isStrong ? 'Konsens' : 'Umstritten'

  return (
    <div className="flex flex-col gap-1 w-full">
      {/* Header row */}
      <div className="flex items-baseline gap-1">
        <span className="text-[13px] font-mono-num font-bold text-vp-ink">{clamped}%</span>
        <span className="text-[12px] text-vp-text-secondary">{label}</span>
      </div>

      {/* Track */}
      <div className="w-full h-[6px] rounded-vp-full bg-vp-border-subtle overflow-hidden">
        <motion.div
          className={['h-[6px] rounded-vp-full', fillClass].join(' ')}
          initial={{ width: 0 }}
          animate={{ width: `${clamped}%` }}
          transition={
            reduceMotion ? { duration: 0 } : { type: 'spring', stiffness: 80, damping: 18 }
          }
        />
      </div>
    </div>
  )
}

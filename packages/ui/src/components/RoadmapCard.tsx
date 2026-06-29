import { ConsensusBar } from './ConsensusBar'

/**
 * RoadmapCard — read-only Kanban-Karte (Figma 133:33).
 *
 * Kein VoteWidget — Score wird als statische Zahl + Label "Stimmen" angezeigt.
 * Ganze Karte verlinkt zur Idea-Detail-View; VoteWidget lebt dort.
 * Token-gebunden, reused ConsensusBar.
 */
interface RoadmapCardProps {
  id: string | number
  title: string
  score: number
  consensusPercent: number
  /** Link zur Idea-Detail-View (z. B. /{board}/idea/{id}) */
  href?: string
}

export function RoadmapCard({ title, score, consensusPercent, href }: RoadmapCardProps) {
  const inner = (
    <div
      className={[
        'flex flex-col gap-4',
        'bg-vp-surface-frost border border-vp-border-frost rounded-vp-lg',
        'backdrop-blur-[14px] backdrop-saturate-[1.2]',
        'p-5',
        'shadow-vp-soft',
        href
          ? 'transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-vp-lift'
          : '',
      ]
        .filter(Boolean)
        .join(' ')}
    >
      {/* Title */}
      <p className="text-[16px] font-semibold font-archivo text-vp-ink leading-[1.22] line-clamp-3">
        {title}
      </p>

      {/* Read-only score display (kein VoteWidget) */}
      <div className="flex items-baseline gap-1.5">
        <span className="font-mono-num font-bold text-[22px] leading-none text-vp-ink">
          {score}
        </span>
        <span className="text-[12px] text-vp-text-muted leading-none">Stimmen</span>
      </div>

      {/* ConsensusBar */}
      <ConsensusBar percent={consensusPercent} />
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

import type { ReactNode } from 'react'

interface EmptyStateProps {
  title: string
  description?: string
  action?: ReactNode
}

export function EmptyState({ title, description, action }: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center py-16 gap-4 text-center">
      <h2 className="font-archivo text-[18px] font-semibold text-vp-ink">{title}</h2>
      {description && <p className="text-[15px] text-vp-text-secondary max-w-sm">{description}</p>}
      {action}
    </div>
  )
}

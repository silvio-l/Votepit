import { motion, useReducedMotion, LayoutGroup } from 'framer-motion'

export type SortValue = 'top' | 'newest' | 'controversial'

interface SortTabsProps {
  value: SortValue
  onChange: (value: SortValue) => void
}

const tabs: Array<{ value: SortValue; label: string }> = [
  { value: 'top',           label: 'Top' },
  { value: 'newest',        label: 'Neu' },
  { value: 'controversial', label: 'Umstritten' },
]

export function SortTabs({ value, onChange }: SortTabsProps) {
  const reduceMotion = useReducedMotion()

  return (
    <LayoutGroup>
      <div
        className={[
          'flex gap-[3px] p-1',
          'bg-vp-surface-frost border border-vp-border-subtle rounded-vp-md',
          'shadow-[0px_8px_24px_-6px_rgba(20,23,26,0.07)]',
        ].join(' ')}
        role="tablist"
        aria-label="Sortierung"
      >
        {tabs.map((tab) => {
          const isActive = tab.value === value
          return (
            <button
              key={tab.value}
              role="tab"
              aria-selected={isActive}
              aria-pressed={isActive}
              onClick={() => onChange(tab.value)}
              className={[
                'relative px-[15px] py-2',
                'text-[13px] rounded-vp-sm',
                'cursor-pointer transition-colors duration-150',
                isActive
                  ? 'text-vp-ink font-semibold font-inter'
                  : 'text-vp-text-muted font-medium font-inter hover:text-vp-ink',
              ].join(' ')}
            >
              {/* Active background indicator */}
              {isActive && (
                <motion.span
                  layoutId="sort-tab-active"
                  className="absolute inset-0 bg-vp-surface border border-vp-border-subtle rounded-vp-sm"
                  style={{ zIndex: -1 }}
                  transition={
                    reduceMotion
                      ? { duration: 0 }
                      : { type: 'spring', stiffness: 400, damping: 30 }
                  }
                />
              )}
              {tab.label}
            </button>
          )
        })}
      </div>
    </LayoutGroup>
  )
}

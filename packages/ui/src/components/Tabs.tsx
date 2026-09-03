import type { KeyboardEvent, ReactNode } from 'react'
import { useRef } from 'react'
import { cx } from '../lib/cx'

export interface TabItem<V extends string> {
  value: V
  label: string
  /** Optional 16px glyph before the label. */
  icon?: ReactNode
  /** Optional trailing count. */
  count?: number
}

interface TabsProps<V extends string> {
  items: ReadonlyArray<TabItem<V>>
  value: V
  onChange: (value: V) => void
  ariaLabel: string
  /** "line": underlined tabs on a baseline rule (page-level). "segmented": a compact control. */
  variant?: 'line' | 'segmented'
  className?: string
}

/**
 * Accessible tablist (roving tabindex, arrow keys, Home/End). Controls a view
 * on the same page — never navigation. The line variant grows its ink
 * underline from the centre; the segmented variant lifts the active pill.
 */
export function Tabs<V extends string>({
  items,
  value,
  onChange,
  ariaLabel,
  variant = 'line',
  className,
}: TabsProps<V>) {
  const refs = useRef<Array<HTMLButtonElement | null>>([])

  const focusIndex = (i: number) => {
    const n = items.length
    const idx = ((i % n) + n) % n
    const item = items[idx]
    refs.current[idx]?.focus()
    if (item) onChange(item.value)
  }

  const onKeyDown = (e: KeyboardEvent, i: number) => {
    switch (e.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        e.preventDefault()
        focusIndex(i + 1)
        break
      case 'ArrowLeft':
      case 'ArrowUp':
        e.preventDefault()
        focusIndex(i - 1)
        break
      case 'Home':
        e.preventDefault()
        focusIndex(0)
        break
      case 'End':
        e.preventDefault()
        focusIndex(items.length - 1)
        break
    }
  }

  const line = variant === 'line'

  return (
    <div
      role="tablist"
      aria-label={ariaLabel}
      className={cx(
        'flex max-w-full overflow-x-auto',
        line
          ? 'gap-1 border-b border-vp-border-subtle -mb-px'
          : 'gap-0.5 p-0.5 rounded-vp-md border border-vp-border-subtle bg-vp-surface-sunken/70 w-fit',
        className,
      )}
    >
      {items.map((item, i) => {
        const active = item.value === value
        return (
          <button
            key={item.value}
            ref={(el) => {
              refs.current[i] = el
            }}
            type="button"
            role="tab"
            aria-selected={active}
            tabIndex={active ? 0 : -1}
            onClick={() => onChange(item.value)}
            onKeyDown={(e) => onKeyDown(e, i)}
            className={cx(
              'relative inline-flex items-center gap-1.5 cursor-pointer whitespace-nowrap font-medium transition-colors duration-150',
              line
                ? cx(
                    'h-10 px-3 text-vp-base',
                    "after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full after:bg-vp-ink after:content-[''] after:origin-center after:transition-transform after:duration-200 after:ease-vp-out",
                    active
                      ? 'text-vp-ink after:scale-x-100'
                      : 'text-vp-text-secondary hover:text-vp-ink after:scale-x-0 hover:after:scale-x-50 hover:after:bg-vp-rule',
                  )
                : cx(
                    'h-7 px-2.5 rounded-[5px] text-vp-sm',
                    active
                      ? 'bg-vp-surface text-vp-ink shadow-vp-xs'
                      : 'text-vp-text-secondary hover:text-vp-ink',
                  ),
            )}
          >
            {item.icon && (
              <span aria-hidden="true" className="inline-flex shrink-0">
                {item.icon}
              </span>
            )}
            {item.label}
            {item.count !== undefined && (
              <span
                className={cx(
                  'font-mono-num text-vp-2xs px-1 rounded-vp-xs leading-4',
                  active ? 'bg-vp-ink-soft text-vp-ink' : 'bg-vp-surface-sunken text-vp-text-muted',
                )}
              >
                {item.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}

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

export interface TabsProps<V extends string> {
  items: ReadonlyArray<TabItem<V>>
  value: V
  onChange: (value: V) => void
  /** Accessible name of the tab list, localized by the app. */
  ariaLabel: string
  /** Test id prefix; each tab gets `<prefix>-<value>`. */
  testIdPrefix: string
  className?: string
}

/**
 * Segmented control for a view on the same page (never navigation): a row of
 * cells inside one ink outline, the active cell filled ink. Roving tabindex with
 * arrow keys, Home and End.
 */
export function Tabs<V extends string>({
  items,
  value,
  onChange,
  ariaLabel,
  testIdPrefix,
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

  return (
    <div role="tablist" aria-label={ariaLabel} className={cx('ds-tabs', className)}>
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
            data-testid={`${testIdPrefix}-${item.value}`}
            aria-selected={active}
            tabIndex={active ? 0 : -1}
            onClick={() => onChange(item.value)}
            onKeyDown={(e) => onKeyDown(e, i)}
            className={cx('ds-tabs__tab', active && 'ds-tabs__tab--active')}
          >
            {item.icon && (
              <span aria-hidden="true" className="ds-tabs__icon">
                {item.icon}
              </span>
            )}
            {item.label}
            {item.count !== undefined && (
              <span className={cx('ds-tabs__count', active && 'ds-tabs__count--active')}>
                {item.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}

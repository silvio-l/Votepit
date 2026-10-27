import type { ElementType, KeyboardEvent, ReactNode } from 'react'
import { useEffect, useId, useRef, useState } from 'react'
import { cx } from '../lib/cx'

export type DsMenuItem =
  | {
      kind: 'link'
      label: ReactNode
      href: string
      current?: boolean
      icon?: ReactNode
      /** Stable test id (default: positional). */
      testId?: string
      /** Another site: rendered as a plain `a`, never through `linkAs`. */
      external?: boolean
    }
  | {
      kind: 'action'
      label: ReactNode
      onSelect: () => void
      tone?: 'default' | 'danger'
      icon?: ReactNode
      /** Radio choice (language switch): true marks the current choice. */
      checked?: boolean
      /** Stable test id (default: positional). */
      testId?: string
    }
  | { kind: 'separator' }

export interface DsMenuProps {
  /** Stable data-testid on the trigger (items get `<testId>-item-<n>`). */
  testId: string
  /** Trigger content. */
  label: ReactNode
  /** Accessible name for the trigger and the menu. */
  ariaLabel: string
  items: DsMenuItem[]
  align?: 'start' | 'end'
  /** Opens the panel below (default) or above the trigger, e.g. for a sidebar footer. */
  placement?: 'down' | 'up'
  /** Router link component for `link` items (adapted to accept `href`). Defaults to `a`. */
  linkAs?: ElementType
  className?: string
}

/**
 * Disclosure menu: Enter/Space/ArrowDown opens, ArrowUp/Down/Home/End move,
 * Esc or Tab closes and Esc returns focus to the trigger, an outside click or focus
 * leaving the widget closes it. Same keyboard model as the legacy Menu.
 */
export function DsMenu({
  testId,
  label,
  ariaLabel,
  items,
  align = 'end',
  placement = 'down',
  linkAs,
  className,
}: DsMenuProps) {
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const itemRefs = useRef<(HTMLElement | null)[]>([])
  const pendingFocus = useRef<'first' | 'last' | null>(null)
  const panelId = useId()
  const Link = (linkAs ?? 'a') as ElementType

  useEffect(() => {
    if (!open || pendingFocus.current === null) return
    const focusable = itemRefs.current.filter((el): el is HTMLElement => el !== null)
    const target = pendingFocus.current === 'first' ? focusable[0] : focusable[focusable.length - 1]
    pendingFocus.current = null
    target?.focus()
  }, [open])

  const close = (returnFocus: boolean) => {
    setOpen(false)
    if (returnFocus) triggerRef.current?.focus()
  }

  const moveFocus = (delta: 'first' | 'last' | 'next' | 'prev') => {
    const focusable = itemRefs.current.filter((el): el is HTMLElement => el !== null)
    if (focusable.length === 0) return
    const current = focusable.indexOf(document.activeElement as HTMLElement)
    let index: number
    if (delta === 'first') index = 0
    else if (delta === 'last') index = focusable.length - 1
    else if (delta === 'next') index = current < 0 ? 0 : (current + 1) % focusable.length
    else
      index =
        current < 0 ? focusable.length - 1 : (current - 1 + focusable.length) % focusable.length
    focusable[index]?.focus()
  }

  const onTriggerKeyDown = (e: KeyboardEvent<HTMLButtonElement>) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      pendingFocus.current = e.key === 'ArrowDown' ? 'first' : 'last'
      setOpen(true)
    }
  }

  const onPanelKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    switch (e.key) {
      case 'Escape':
        e.preventDefault()
        close(true)
        break
      case 'Tab':
        close(false)
        break
      case 'ArrowDown':
        e.preventDefault()
        moveFocus('next')
        break
      case 'ArrowUp':
        e.preventDefault()
        moveFocus('prev')
        break
      case 'Home':
        e.preventDefault()
        moveFocus('first')
        break
      case 'End':
        e.preventDefault()
        moveFocus('last')
        break
    }
  }

  let itemIndex = -1
  return (
    // biome-ignore lint/a11y/noStaticElementInteractions: focus-out only; the trigger and panel carry the interactive roles
    <div
      ref={rootRef}
      className={cx('ds-menu', className)}
      onBlur={(e) => {
        if (open && !rootRef.current?.contains(e.relatedTarget as Node | null)) setOpen(false)
      }}
    >
      <button
        ref={triggerRef}
        type="button"
        className="ds-menu__trigger"
        data-testid={testId}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        aria-label={ariaLabel}
        onClick={() => (open ? close(false) : setOpen(true))}
        onKeyDown={onTriggerKeyDown}
      >
        {label}
      </button>
      {open && (
        <div
          id={panelId}
          role="menu"
          aria-label={ariaLabel}
          className={cx(
            'ds-menu__panel',
            align === 'start' && 'ds-menu__panel--start',
            placement === 'up' && 'ds-menu__panel--up',
          )}
          onKeyDown={onPanelKeyDown}
        >
          {items.map((item, i) => {
            // Items are a fixed list that is never reordered, so the position is a stable key.
            if (item.kind === 'separator') {
              // biome-ignore lint/suspicious/noArrayIndexKey: fixed list, never reordered
              return <hr key={`sep-${i}`} className="ds-menu__separator" />
            }
            itemIndex += 1
            const n = itemIndex
            const itemTestId = `${testId}-item-${n}`
            const setRef = (el: HTMLElement | null) => {
              itemRefs.current[n] = el
            }
            if (item.kind === 'link') {
              const ItemLink = item.external ? 'a' : Link
              return (
                <ItemLink
                  // biome-ignore lint/suspicious/noArrayIndexKey: fixed list, never reordered
                  key={`link-${i}`}
                  ref={setRef}
                  href={item.href}
                  role="menuitem"
                  data-testid={item.testId ?? itemTestId}
                  aria-current={item.current ? 'page' : undefined}
                  className="ds-menu__item"
                  onClick={() => close(false)}
                >
                  {item.icon && <span aria-hidden="true">{item.icon}</span>}
                  {item.label}
                </ItemLink>
              )
            }
            const radio = item.checked !== undefined
            return (
              <button
                // biome-ignore lint/suspicious/noArrayIndexKey: fixed list, never reordered
                key={`action-${i}`}
                ref={setRef}
                type="button"
                {...(radio
                  ? { role: 'menuitemradio', 'aria-checked': item.checked }
                  : { role: 'menuitem' })}
                data-testid={item.testId ?? itemTestId}
                className={cx('ds-menu__item', item.tone === 'danger' && 'ds-menu__item--danger')}
                onClick={() => {
                  close(false)
                  item.onSelect()
                }}
              >
                {item.icon && <span aria-hidden="true">{item.icon}</span>}
                {item.label}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}

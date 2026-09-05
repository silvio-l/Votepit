import type { ElementType, FocusEvent, KeyboardEvent, ReactNode } from 'react'
import { useEffect, useId, useRef, useState } from 'react'
import { cx } from '../lib/cx'
import { buttonClassName } from './Button'

export type MenuItem =
  | { kind: 'link'; label: string; href: string; current?: boolean; icon?: ReactNode }
  | { kind: 'action'; label: string; onSelect: () => void; tone?: 'default' | 'danger'; icon?: ReactNode }
  | { kind: 'separator' }
  | { kind: 'header'; label: ReactNode }

interface MenuProps {
  /** Trigger content (text and/or glyph). Rendered as a `sm` secondary button. */
  label: ReactNode
  /** Accessible name for trigger and menu when `label` is not plain text. */
  ariaLabel?: string
  items: MenuItem[]
  /** Which edge of the trigger the panel aligns to. */
  align?: 'start' | 'end'
  /**
   * Which side of the trigger the panel opens toward. "down" (default) or
   * "up" — for a trigger pinned near the bottom of the viewport (e.g. a
   * sidebar footer), where a downward panel would run off-screen.
   */
  placement?: 'down' | 'up'
  /** "button" (default, bordered) or "ghost" (borderless, for icon triggers). */
  triggerVariant?: 'button' | 'ghost'
  /** Hide the chevron (icon-only triggers). */
  hideChevron?: boolean
  className?: string
  /**
   * Element `{ kind: 'link' }` items are rendered as instead of a plain
   * `<a>` — pass a host router's Link (adapted to accept an `href` prop,
   * e.g. `to={href}`) so item clicks stay client-side navigations instead
   * of full page reloads. Defaults to `'a'` (a non-router consumer keeps
   * working unchanged). Same convention as BackLink's `as` prop.
   */
  linkAs?: ElementType
}

function Chevron({ open }: { open: boolean }) {
  return (
    <svg
      viewBox="0 0 16 16"
      width="14"
      height="14"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className={cx(
        'shrink-0 text-vp-text-muted transition-transform duration-150',
        open && 'rotate-180',
      )}
    >
      <path d="M4 6l4 4 4-4" />
    </svg>
  )
}

const itemClassName =
  'flex w-full items-center gap-2.5 h-9 px-2.5 rounded-vp-md text-vp-base text-left no-underline cursor-pointer transition-colors duration-100 outline-none'

/**
 * Menu button (WAI-ARIA "menu button" pattern): one trigger, one panel of
 * links and actions. The panel is an overlay — a white plane with the 2px
 * ink edge, scaled in from 96%. Arrow keys move between items, Home/End jump,
 * Escape and Tab close, focus returns to the trigger. Used for grouped
 * controls that do not belong in the page nav (the account menu).
 */
export function Menu({
  label,
  ariaLabel,
  items,
  align = 'end',
  placement = 'down',
  triggerVariant = 'button',
  hideChevron = false,
  className,
  linkAs,
}: MenuProps) {
  const NavLink = (linkAs ?? 'a') as ElementType
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const itemRefs = useRef<Array<HTMLElement | null>>([])
  const pendingFocus = useRef<'first' | 'last' | null>(null)
  const ids = useId()
  const triggerId = `${ids}-trigger`
  const menuId = `${ids}-menu`

  const focusable = () => itemRefs.current.filter((el): el is HTMLElement => el !== null)

  const close = (restoreFocus: boolean) => {
    setOpen(false)
    if (restoreFocus) triggerRef.current?.focus()
  }

  const openAndFocus = (which: 'first' | 'last') => {
    pendingFocus.current = which
    setOpen(true)
  }

  // Focus the requested item once the panel is in the DOM.
  useEffect(() => {
    if (!open || pendingFocus.current === null) return
    const els = focusable()
    const target = pendingFocus.current === 'first' ? els[0] : els[els.length - 1]
    pendingFocus.current = null
    target?.focus()
  }, [open])

  // Light dismiss: pointer down anywhere outside the root closes the menu.
  useEffect(() => {
    if (!open) return
    const onPointerDown = (e: PointerEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', onPointerDown)
    return () => document.removeEventListener('pointerdown', onPointerDown)
  }, [open])

  const onTriggerKeyDown = (e: KeyboardEvent<HTMLButtonElement>) => {
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      openAndFocus('first')
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      openAndFocus('last')
    }
  }

  const onMenuKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const els = focusable()
    const i = els.findIndex((el) => el === document.activeElement)
    const n = els.length
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault()
        els[(i + 1) % n]?.focus()
        break
      case 'ArrowUp':
        e.preventDefault()
        els[(i - 1 + n) % n]?.focus()
        break
      case 'Home':
        e.preventDefault()
        els[0]?.focus()
        break
      case 'End':
        e.preventDefault()
        els[n - 1]?.focus()
        break
      case 'Escape':
        e.preventDefault()
        close(true)
        break
      case 'Tab':
        close(false)
        break
    }
  }

  // Focus leaving the whole widget (e.g. Tab out of the trigger) closes it.
  const onBlur = (e: FocusEvent<HTMLDivElement>) => {
    if (open && !rootRef.current?.contains(e.relatedTarget as Node | null)) setOpen(false)
  }

  let itemIndex = -1

  return (
    <div ref={rootRef} className={cx('relative', className)} onBlur={onBlur}>
      <button
        ref={triggerRef}
        id={triggerId}
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        aria-label={ariaLabel}
        onClick={() => (open ? close(false) : openAndFocus('first'))}
        onKeyDown={onTriggerKeyDown}
        className={buttonClassName(
          triggerVariant === 'ghost' ? 'ghost' : 'secondary',
          'sm',
          cx('gap-1.5', !hideChevron && 'pr-2', open && 'bg-vp-bg'),
        )}
      >
        {label}
        {!hideChevron && <Chevron open={open} />}
      </button>

      {open && (
        <div
          id={menuId}
          role="menu"
          aria-label={ariaLabel}
          aria-labelledby={ariaLabel === undefined ? triggerId : undefined}
          onKeyDown={onMenuKeyDown}
          className={cx(
            'absolute z-50 min-w-[14rem] max-w-[calc(100vw-2rem)] p-1',
            'vp-overlay animate-vp-scale-in',
            placement === 'up' ? 'bottom-full mb-1.5' : 'top-full mt-1.5',
            align === 'end'
              ? placement === 'up'
                ? 'right-0 origin-bottom-right'
                : 'right-0 origin-top-right'
              : placement === 'up'
                ? 'left-0 origin-bottom-left'
                : 'left-0 origin-top-left',
          )}
        >
          {items.map((item, i) => {
            if (item.kind === 'separator') {
              return (
                <div
                  // biome-ignore lint/suspicious/noArrayIndexKey: separators carry no identity of their own.
                  key={`sep-${i}`}
                  role="separator"
                  className="my-1 h-px bg-vp-rule"
                />
              )
            }
            if (item.kind === 'header') {
              return (
                <div
                  // biome-ignore lint/suspicious/noArrayIndexKey: headers are static labels.
                  key={`head-${i}`}
                  role="presentation"
                  className="px-2.5 pt-2 pb-1.5 text-vp-xs text-vp-text-muted"
                >
                  {item.label}
                </div>
              )
            }
            itemIndex += 1
            const idx = itemIndex
            const glyph = item.icon && (
              <span aria-hidden="true" className="inline-flex shrink-0 text-vp-text-muted">
                {item.icon}
              </span>
            )
            if (item.kind === 'link') {
              return (
                <NavLink
                  key={`link-${item.href}`}
                  ref={(el: HTMLElement | null) => {
                    itemRefs.current[idx] = el
                  }}
                  role="menuitem"
                  tabIndex={-1}
                  href={item.href}
                  aria-current={item.current ? 'page' : undefined}
                  onClick={() => close(false)}
                  className={cx(
                    itemClassName,
                    item.current ? 'font-semibold text-vp-ink bg-vp-ink-softer' : 'text-vp-ink',
                    'hover:bg-vp-ink-soft focus-visible:bg-vp-ink-soft',
                  )}
                >
                  {glyph}
                  {item.label}
                </NavLink>
              )
            }
            return (
              <button
                key={`action-${item.label}`}
                ref={(el) => {
                  itemRefs.current[idx] = el
                }}
                type="button"
                role="menuitem"
                tabIndex={-1}
                onClick={() => {
                  close(false)
                  item.onSelect()
                }}
                className={cx(
                  itemClassName,
                  item.tone === 'danger'
                    ? 'text-vp-vote-down-strong hover:bg-vp-vote-down-soft focus-visible:bg-vp-vote-down-soft'
                    : 'text-vp-ink hover:bg-vp-ink-soft focus-visible:bg-vp-ink-soft',
                )}
              >
                {glyph}
                {item.label}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}

import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

/** A keyboard key cap for shortcut hints ("⌘K", "Esc"). Decorative — always next to a text label. */
export function Kbd({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <kbd aria-hidden="true" className={cx('vp-kbd', className)}>
      {children}
    </kbd>
  )
}

import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

interface FieldsetProps {
  legend: string
  /** Secondary line under the legend. */
  hint?: string
  required?: boolean
  className?: string
  children: ReactNode
}

/**
 * A group of related controls (a set of checkboxes, a radio list) under one
 * legend — the same label/hint typography FieldShell gives a single field,
 * with real `<fieldset>`/`<legend>` semantics so AT reads the group name
 * before each option.
 */
export function Fieldset({ legend, hint, required, className, children }: FieldsetProps) {
  return (
    <fieldset className={cx('flex flex-col gap-1.5 min-w-0 border-0 p-0 m-0', className)}>
      <legend className="text-vp-sm font-medium text-vp-ink leading-5 p-0">
        {legend}
        {required && (
          <span className="text-vp-vote-down-strong ml-0.5" aria-hidden="true">
            *
          </span>
        )}
      </legend>
      {hint && <p className="text-vp-xs leading-4 text-vp-text-muted">{hint}</p>}
      <div className="flex flex-col gap-2 mt-1">{children}</div>
    </fieldset>
  )
}

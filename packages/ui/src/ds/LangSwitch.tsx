import type { ReactNode } from 'react'
import { DsMenu } from './Menu'

export interface LangOption {
  code: string
  /** Full language name, shown in the menu. */
  label: string
}

export interface LangSwitchProps {
  testId: string
  /** Current locale code, e.g. "de". */
  value: string
  options: LangOption[]
  onChange: (code: string) => void
  /** Accessible name prefix, e.g. "Language". */
  ariaLabel: string
  /** Short code shown on the trigger; defaults to the current code. */
  triggerLabel?: ReactNode
}

/** Compact locale switcher: a disclosure menu of radio choices. */
export function LangSwitch({
  testId,
  value,
  options,
  onChange,
  ariaLabel,
  triggerLabel,
}: LangSwitchProps) {
  const current = options.find((o) => o.code === value)
  return (
    <DsMenu
      testId={testId}
      className="ds-lang-switch"
      ariaLabel={`${ariaLabel}: ${current?.label ?? value}`}
      label={triggerLabel ?? value}
      align="end"
      items={options.map((o) => ({
        kind: 'action' as const,
        label: o.label,
        checked: o.code === value,
        onSelect: () => onChange(o.code),
      }))}
    />
  )
}

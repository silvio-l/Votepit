import { Tabs } from './Tabs'

export type SortValue = 'top' | 'newest' | 'controversial'

const SORT_VALUES: SortValue[] = ['top', 'newest', 'controversial']

interface SortTabsProps {
  value: SortValue
  onChange: (value: SortValue) => void
  /** Caller-supplied, localized texts. The component ships no default wording. */
  ariaLabel: string
  labels: Record<SortValue, string>
  variant?: 'line' | 'segmented'
  tone?: 'ink' | 'accent'
  className?: string
}

export function SortTabs({
  value,
  onChange,
  ariaLabel,
  labels,
  variant = 'segmented',
  tone = 'ink',
  className,
}: SortTabsProps) {
  const items = SORT_VALUES.map((v) => ({ value: v, label: labels[v] }))

  return (
    <Tabs
      items={items}
      value={value}
      onChange={onChange}
      ariaLabel={ariaLabel}
      variant={variant}
      tone={tone}
      className={className}
    />
  )
}

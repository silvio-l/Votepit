import { Tabs } from './Tabs'

export type SortValue = 'top' | 'newest' | 'controversial'

interface SortTabsProps {
  value: SortValue
  onChange: (value: SortValue) => void
  /** i18n overrides — all default to German so existing callers keep working untranslated. */
  ariaLabel?: string
  labels?: Partial<Record<SortValue, string>>
  variant?: 'line' | 'segmented'
}

const defaultLabels: Record<SortValue, string> = {
  top: 'Top',
  newest: 'Neu',
  controversial: 'Umstritten',
}

export function SortTabs({
  value,
  onChange,
  ariaLabel = 'Sortierung',
  labels = defaultLabels,
  variant = 'segmented',
}: SortTabsProps) {
  const items = (Object.keys(defaultLabels) as SortValue[]).map((v) => ({
    value: v,
    label: labels[v] ?? defaultLabels[v],
  }))

  return (
    <Tabs items={items} value={value} onChange={onChange} ariaLabel={ariaLabel} variant={variant} />
  )
}

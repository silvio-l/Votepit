/**
 * ThemePicker — the colour-scheme switch in the app bar, next to the bell:
 * an icon trigger showing the current choice (monitor / sun / moon) that opens
 * a menu of System / Light / Dark. Same store as ThemeChoice and the account
 * menu entries (lib/theme.ts), so all three stay in step.
 */

import { DsMenu } from '@votepit/ui/ds'
import { Monitor, Moon, Sun } from 'lucide-react'
import { useT } from '../lib/i18n/context'
import { setThemePref, type ThemePref, useThemeState } from '../lib/theme'

const OPTIONS: ReadonlyArray<{
  pref: ThemePref
  key: 'themeChoice.system' | 'themeChoice.light' | 'themeChoice.dark'
  Icon: typeof Sun
}> = [
  { pref: 'system', key: 'themeChoice.system', Icon: Monitor },
  { pref: 'light', key: 'themeChoice.light', Icon: Sun },
  { pref: 'dark', key: 'themeChoice.dark', Icon: Moon },
]

export function ThemePicker() {
  const t = useT('common')
  const { pref } = useThemeState()
  const current = OPTIONS.find((o) => o.pref === pref) ?? OPTIONS[0]
  const CurrentIcon = current.Icon
  return (
    <DsMenu
      testId="theme-picker"
      className="ds-theme-switch"
      ariaLabel={`${t('themeChoice.label')}: ${t(current.key)}`}
      label={<CurrentIcon size={18} aria-hidden="true" />}
      align="end"
      items={OPTIONS.map(({ pref: option, key, Icon }) => ({
        kind: 'action' as const,
        label: t(key),
        icon: <Icon size={16} aria-hidden="true" />,
        checked: pref === option,
        testId: `theme-picker-${option}`,
        onSelect: () => setThemePref(option),
      }))}
    />
  )
}

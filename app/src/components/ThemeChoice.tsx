import { cx } from '@votepit/ui/ds'
import { useT } from '../lib/i18n/context'
import { setThemePref, type ThemePref, useThemeState } from '../lib/theme'

const OPTIONS: ReadonlyArray<{
  pref: ThemePref
  key: 'themeChoice.system' | 'themeChoice.light' | 'themeChoice.dark'
}> = [
  { pref: 'system', key: 'themeChoice.system' },
  { pref: 'light', key: 'themeChoice.light' },
  { pref: 'dark', key: 'themeChoice.dark' },
]

/**
 * The viewer's colour scheme as a small radio group (System / Hell / Dunkel). The logged-out visitor's
 * way to it: the account menu only exists for signed-in users, so the footers of the public pages carry this.
 * Same store as the account-menu entries (lib/theme.ts), so both stay in step.
 */
export function ThemeChoice({ className }: { className?: string }) {
  const t = useT('common')
  const { pref } = useThemeState()
  return (
    <div
      role="radiogroup"
      aria-label={t('themeChoice.label')}
      data-testid="theme-choice"
      className={cx('inline-flex flex-wrap items-center gap-1 text-vp-xs', className)}
    >
      {OPTIONS.map((option) => {
        const selected = pref === option.pref
        return (
          <button
            key={option.pref}
            type="button"
            role="radio"
            aria-checked={selected}
            data-testid={`theme-choice-${option.pref}`}
            onClick={() => setThemePref(option.pref)}
            className={cx(
              'min-h-9 rounded-vp-sm border px-2.5 py-1 text-vp-xs font-medium transition-colors duration-ds-fast focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-vp-accent',
              selected
                ? 'border-vp-ink bg-vp-ink text-vp-on-ink'
                : 'border-vp-border-strong bg-vp-surface text-vp-text-secondary hover:text-vp-title',
            )}
          >
            {t(option.key)}
          </button>
        )
      })}
    </div>
  )
}

/**
 * LanguageToggle — the landing's "EN / DE" switch, verbatim: two small-caps
 * words separated by a slash, the current one carrying a 2px ink underline.
 * Used in the app bar (LocalizedHeader) and on the auth shell so the visitor
 * meets the same control on votepit.com, on a board and on the login page.
 */

import { cx } from '@votepit/ui'
import type { Language } from '../lib/i18n/context'
import { useI18n, useT } from '../lib/i18n/context'

const LANGUAGES: Language[] = ['de', 'en']

export function LanguageToggle({ className }: { className?: string }) {
  const { language, setLanguage } = useI18n()
  const t = useT('common')
  return (
    <div
      role="group"
      aria-label={t('language.label')}
      className={cx('inline-flex items-center gap-1.5 text-vp-xs font-mono-num', className)}
    >
      {LANGUAGES.map((lang, i) => (
        <span key={lang} className="inline-flex items-center gap-1.5">
          {i > 0 && (
            <span aria-hidden="true" className="text-vp-text-tertiary select-none">
              /
            </span>
          )}
          <button
            type="button"
            aria-pressed={language === lang}
            aria-label={t(`language.${lang}`)}
            onClick={() => setLanguage(lang)}
            className={cx(
              'inline-flex h-7 items-center uppercase tracking-[0.08em] border-b-2 cursor-pointer transition-colors duration-150',
              language === lang
                ? 'font-bold text-vp-ink border-vp-ink'
                : 'font-medium text-vp-text-muted border-transparent hover:text-vp-ink',
            )}
          >
            {lang}
          </button>
        </span>
      ))}
    </div>
  )
}

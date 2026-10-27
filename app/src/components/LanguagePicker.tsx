/**
 * LanguagePicker — the app's language switch: a compact ghost trigger
 * showing the current language code, opening a menu of all UI languages
 * listed by their own names (a reader finds their language without knowing
 * the current one). Used in the app bar (LocalizedHeader) and on the auth
 * shell so the visitor meets the same control on a board and on the login
 * page. Six languages no longer fit the landing's inline "EN / DE" switch.
 */

import { LangSwitch } from '@votepit/ui/ds'
import { LANGUAGE_NAMES, LANGUAGES, useI18n, useT } from '../lib/i18n/context'

export function LanguagePicker({ className: _className }: { className?: string }) {
  const { language, setLanguage } = useI18n()
  const t = useT('common')
  return (
    <LangSwitch
      testId="language-switch"
      value={language}
      ariaLabel={t('language.label')}
      options={LANGUAGES.map((lang) => ({ code: lang, label: LANGUAGE_NAMES[lang] }))}
      onChange={(code) => setLanguage(code as typeof language)}
      triggerLabel={language}
    />
  )
}

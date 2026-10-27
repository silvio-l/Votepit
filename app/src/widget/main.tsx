import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '../index.css'
import { parseWidgetConfig } from './config'
import { detectWidgetLanguage, widgetTranslator } from './i18n'
import { WidgetApp } from './WidgetApp'

const rootElement = document.getElementById('root')
if (!rootElement) throw new Error('Root element #root not found')

const config = parseWidgetConfig(
  document.querySelector('meta[name="votepit-widget"]')?.getAttribute('content') ?? null,
  window.location.pathname,
)
// The board's display mode (P-26). Set before the first render so the iframe never paints the other scheme;
// "system" leaves the attribute off and the DS media query follows the visitor's own device setting.
if (config !== null && config.board.themeMode !== 'system') {
  document.documentElement.dataset.theme = config.board.themeMode
}
const t = widgetTranslator(detectWidgetLanguage(navigator.languages))

createRoot(rootElement).render(
  <StrictMode>
    {config === null ? (
      <p className="p-4 text-vp-sm">{t('loadFailed')}</p>
    ) : (
      <WidgetApp config={config} t={t} />
    )}
  </StrictMode>,
)

import { InlineNotice } from '@votepit/ui/ds'
import { useEffect, useRef, useState } from 'react'
import { useT } from '../lib/i18n/context'
import { loadTurnstileScript } from '../lib/turnstile'

interface TurnstileWidgetProps {
  siteKey: string
  /** Widget action the server verifies (e.g. `anon_idea_submit`). */
  action: string
  /** Receives the solved token, or null once it expired/failed. */
  onToken: (token: string | null) => void
  /** Change it to render a fresh widget (a token is single-use). */
  resetKey?: number
}

/**
 * Inline Cloudflare Turnstile widget — used where the challenge is part of
 * the form itself (guest idea submission, ADR 0006: every submission without
 * an account is verified), unlike the on-demand TurnstileChallengeModal.
 */
export function TurnstileWidget({ siteKey, action, onToken, resetKey = 0 }: TurnstileWidgetProps) {
  const t = useT('common')
  const containerRef = useRef<HTMLDivElement>(null)
  const onTokenRef = useRef(onToken)
  onTokenRef.current = onToken
  const [loadError, setLoadError] = useState(false)

  // biome-ignore lint/correctness/useExhaustiveDependencies: resetKey intentionally re-renders the widget.
  useEffect(() => {
    setLoadError(false)
    onTokenRef.current(null)
    let widgetId: string | null = null
    let cancelled = false

    void loadTurnstileScript()
      .then(() => {
        if (cancelled || containerRef.current === null || window.turnstile === undefined) return
        widgetId = window.turnstile.render(containerRef.current, {
          sitekey: siteKey,
          action,
          callback: (token) => onTokenRef.current(token),
          'expired-callback': () => onTokenRef.current(null),
          'error-callback': () => {
            onTokenRef.current(null)
            setLoadError(true)
          },
        })
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })

    return () => {
      cancelled = true
      if (widgetId !== null && window.turnstile !== undefined) {
        window.turnstile.remove(widgetId)
      }
    }
  }, [siteKey, action, resetKey])

  return (
    <div className="flex flex-col gap-2">
      <div ref={containerRef} data-testid="turnstile-widget" />
      {loadError && <InlineNotice tone="error">{t('turnstile.loadError')}</InlineNotice>}
    </div>
  )
}

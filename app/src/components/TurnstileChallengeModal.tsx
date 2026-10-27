import { Button, InlineNotice } from '@votepit/ui/ds'
import { useEffect, useId, useRef, useState } from 'react'
import { useT } from '../lib/i18n/context'
import { loadTurnstileScript } from '../lib/turnstile'

interface TurnstileChallengeModalProps {
  open: boolean
  siteKey: string
  onSolved: (token: string) => void
  onCancel: () => void
}

/**
 * Anti-automation challenge widget (OWASP/BSI audit remediation issue 20).
 * Rendered only when an action's response carries `error.key ===
 * 'turnstile_required'` — see useTurnstileChallenge — never shown to an
 * ordinary user up front.
 */
export function TurnstileChallengeModal({
  open,
  siteKey,
  onSolved,
  onCancel,
}: TurnstileChallengeModalProps) {
  const t = useT('common')
  const titleId = useId()
  const containerRef = useRef<HTMLDivElement>(null)
  const [loadError, setLoadError] = useState(false)

  useEffect(() => {
    if (!open) return
    setLoadError(false)
    let widgetId: string | null = null
    let cancelled = false

    void loadTurnstileScript()
      .then(() => {
        if (cancelled || containerRef.current === null || window.turnstile === undefined) return
        widgetId = window.turnstile.render(containerRef.current, {
          sitekey: siteKey,
          callback: onSolved,
          'error-callback': () => setLoadError(true),
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
  }, [open, siteKey, onSolved])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-end sm:items-center justify-center p-4 animate-vp-fade-in">
      <button
        type="button"
        aria-hidden="true"
        tabIndex={-1}
        onClick={onCancel}
        className="absolute inset-0 bg-vp-ink/60 cursor-default"
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="w-full max-w-sm vp-overlay overflow-hidden animate-vp-scale-in"
      >
        <div className="px-5 pt-5 pb-4">
          <h2
            id={titleId}
            className="font-archivo font-bold text-vp-lg text-vp-title tracking-[-0.02em] leading-6"
          >
            {t('turnstile.title')}
          </h2>
          <p className="mt-1.5 text-vp-sm text-vp-text-secondary text-pretty">
            {t('turnstile.description')}
          </p>
        </div>
        <div className="px-5 pb-5 flex flex-col gap-3">
          <div ref={containerRef} />
          {loadError && <InlineNotice tone="error">{t('turnstile.loadError')}</InlineNotice>}
          <div className="flex justify-end">
            <Button type="button" variant="secondary" testId="turnstile-cancel" onClick={onCancel}>
              {t('turnstile.cancel')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}

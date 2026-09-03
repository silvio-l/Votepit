import { buttonClassName, EmptyState } from '@votepit/ui'
import type { ReactNode } from 'react'
import { useT } from '../lib/i18n/context'
import { Sentry } from '../lib/sentry'

/**
 * Catches any render-time error anywhere below it (React error-boundary
 * semantics — event-handler and async errors are NOT caught here, but ARE
 * still captured by the global handlers Sentry.init() wires up on its own).
 * Sentry.ErrorBoundary reports the error, then renders our fallback instead
 * of leaving the visitor on a blank/half-crashed page.
 */
function CrashFallback() {
  const t = useT('errorBoundary')
  return (
    <div className="min-h-screen bg-vp-bg flex items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm vp-sheet vp-sheet--ruled" role="alert">
        <EmptyState
          title={t('title')}
          description={t('message')}
          action={
            <button
              type="button"
              className={buttonClassName('primary', 'md')}
              onClick={() => window.location.reload()}
            >
              {t('reload')}
            </button>
          }
        />
      </div>
    </div>
  )
}

export function AppErrorBoundary({ children }: { children: ReactNode }) {
  return <Sentry.ErrorBoundary fallback={<CrashFallback />}>{children}</Sentry.ErrorBoundary>
}

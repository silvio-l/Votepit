import { useCallback, useRef, useState } from 'react'
import type { ApiError } from '../lib/api'

interface ChallengeState {
  open: boolean
  siteKey: string
}

/**
 * Wraps a mutating action (createIdea/createComment/vote) so that a
 * `turnstile_required` response (see TurnstileGuard) transparently opens
 * the challenge widget, then retries the same action once solved — the
 * caller only has to pass `turnstileToken` through to the underlying API
 * call and render <TurnstileChallengeModal {...modalProps} />.
 *
 * Ordinary requests never see the modal at all (see TurnstileGuard's class
 * doc — only an IP that's repeatedly tripped the existing rate limits is
 * ever challenged), so this stays a no-op wrapper for almost every call.
 */
export function useTurnstileChallenge() {
  const [challenge, setChallenge] = useState<ChallengeState>({ open: false, siteKey: '' })
  const resolveRef = useRef<((token: string) => void) | null>(null)
  const rejectRef = useRef<((reason: unknown) => void) | null>(null)

  const waitForToken = useCallback((siteKey: string): Promise<string> => {
    return new Promise<string>((resolve, reject) => {
      resolveRef.current = resolve
      rejectRef.current = reject
      setChallenge({ open: true, siteKey })
    })
  }, [])

  const handleSolved = useCallback((token: string) => {
    setChallenge({ open: false, siteKey: '' })
    resolveRef.current?.(token)
    resolveRef.current = null
    rejectRef.current = null
  }, [])

  const handleCancel = useCallback(() => {
    setChallenge({ open: false, siteKey: '' })
    rejectRef.current?.(new Error('turnstile_challenge_cancelled'))
    resolveRef.current = null
    rejectRef.current = null
  }, [])

  /**
   * Runs `action(turnstileToken)`; on a `turnstile_required` ApiError,
   * presents the widget and retries once with the solved token. Any other
   * error (including a cancelled challenge) propagates to the caller.
   */
  const withChallenge = useCallback(
    async <T>(action: (turnstileToken?: string) => Promise<T>): Promise<T> => {
      try {
        return await action()
      } catch (err) {
        const apiErr = err as ApiError
        const siteKey = apiErr?.payload?.site_key
        if (apiErr?.payload?.key !== 'turnstile_required' || siteKey === undefined) {
          throw err
        }
        const token = await waitForToken(siteKey)
        return action(token)
      }
    },
    [waitForToken],
  )

  return {
    withChallenge,
    modalProps: {
      open: challenge.open,
      siteKey: challenge.siteKey,
      onSolved: handleSolved,
      onCancel: handleCancel,
    },
  }
}

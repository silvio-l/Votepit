import { useEffect, useState } from 'react'
import { useSearchParams, useNavigate, Link } from 'react-router-dom'
import { verifyToken } from '../lib/api'
import type { ApiError } from '../lib/api'

type PageState =
  | { phase: 'verifying' }
  | { phase: 'error'; message: string }

export default function VerifyPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const token = searchParams.get('token') ?? ''
  const returnTo = searchParams.get('r') ?? undefined

  const [state, setState] = useState<PageState>({ phase: 'verifying' })

  useEffect(() => {
    if (!token) {
      setState({
        phase: 'error',
        message: 'Der Link ist ungültig oder abgelaufen.',
      })
      return
    }

    verifyToken(token, returnTo)
      .then((data) => {
        // Session cookie set by server; navigate to the redirect target.
        navigate(data.redirect, { replace: true })
      })
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const isInvalidToken =
          apiErr?.payload?.key === 'invalid_token' || apiErr?.status === 400

        setState({
          phase: 'error',
          message: isInvalidToken
            ? 'Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.'
            : (apiErr?.payload?.message ?? 'Anmeldung fehlgeschlagen. Bitte versuche es erneut.'),
        })
      })
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []) // run once on mount; token / returnTo are stable from URL

  return (
    <div className="min-h-screen font-inter flex items-center justify-center px-4">
      <div
        className="w-full max-w-sm p-8 bg-vp-surface backdrop-blur-xl rounded-vp-xl border border-vp-border-subtle text-center"
        style={{ boxShadow: '0 8px 32px rgba(0,0,0,0.08)' }}
      >
        {/* Logo / wordmark */}
        <div className="mb-6">
          <span
            className="font-archivo font-extrabold text-[28px] leading-none tracking-[-0.025em]"
            aria-label="Votepit"
          >
            <span className="text-vp-ink">Vote</span>
            <span className="text-vp-vote-down">pit</span>
          </span>
        </div>

        {state.phase === 'verifying' && <VerifyingState />}
        {state.phase === 'error' && <ErrorState message={state.message} />}
      </div>
    </div>
  )
}

function VerifyingState() {
  return (
    <div className="space-y-3">
      <div
        aria-hidden="true"
        className="mx-auto w-10 h-10 rounded-full border-2 border-vp-accent border-t-transparent animate-spin"
        style={{ willChange: 'transform' }}
      />
      <p className="text-vp-text-secondary text-sm">
        Link wird überprüft…
      </p>
    </div>
  )
}

function ErrorState({ message }: { message: string }) {
  return (
    <div className="space-y-4">
      <div
        aria-hidden="true"
        className="mx-auto w-12 h-12 rounded-full bg-vp-vote-down/10 flex items-center justify-center text-vp-vote-down text-xl"
      >
        ✕
      </div>
      <div>
        <h1 className="font-archivo font-bold text-lg text-vp-ink mb-1">
          Anmeldung fehlgeschlagen
        </h1>
        <p role="alert" className="text-vp-text-secondary text-sm">
          {message}
        </p>
      </div>
      <Link
        to="/login"
        className="block w-full py-3 text-center text-[13px] font-medium font-inter rounded-vp-md bg-vp-ink text-white hover:opacity-90 transition-opacity"
      >
        Neuen Link anfordern
      </Link>
    </div>
  )
}

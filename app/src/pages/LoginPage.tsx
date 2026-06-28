import { Button, TextInput } from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import type { ApiError } from '../lib/api'
import { bootstrap, requestMagicLink } from '../lib/api'

// Votepit hex brand mark — identical paths to the Header logo (viewBox -185 -205 370 410).
const LOGO_TOP =
  'M 165.0 0.0 L 165.0 -44.0 Q 165.0 -72.0 141.6 -87.3 L 23.4 -164.7 Q 0.0 -180.0 -23.4 -164.7 L -141.6 -87.3 Q -165.0 -72.0 -165.0 -44.0 L -165.0 0.0 Z'
const LOGO_BOT =
  'M -165.0 0.0 L -165.0 44.0 Q -165.0 72.0 -141.6 87.3 L -23.4 164.7 Q 0.0 180.0 23.4 164.7 L 141.6 87.3 Q 165.0 72.0 165.0 44.0 L 165.0 0.0 Z'
const LOGO_MID =
  'M -15.9 -112.0 Q 0.0 -122.4 15.9 -112.0 L 96.3 -59.4 Q 112.2 -49.0 112.2 -29.9 L 112.2 29.9 Q 112.2 49.0 96.3 59.4 L 15.9 112.0 Q 0.0 122.4 -15.9 112.0 L -96.3 59.4 Q -112.2 49.0 -112.2 29.9 L -112.2 -29.9 Q -112.2 -49.0 -96.3 -59.4 Z'
const LOGO_DARK =
  'M -11.7 -82.3 Q 0.0 -90.0 11.7 -82.3 L 70.8 -43.7 Q 82.5 -36.0 82.5 -22.0 L 82.5 22.0 Q 82.5 36.0 70.8 43.7 L 11.7 82.3 Q 0.0 90.0 -11.7 82.3 L -70.8 43.7 Q -82.5 36.0 -82.5 22.0 L -82.5 -22.0 Q -82.5 -36.0 -70.8 -43.7 Z'

type PageState =
  | { phase: 'idle' }
  | { phase: 'submitting' }
  | { phase: 'sent' }
  | { phase: 'error'; message: string }

export default function LoginPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const returnTo = searchParams.get('r') ?? undefined

  const [email, setEmail] = useState('')
  const [state, setState] = useState<PageState>({ phase: 'idle' })

  // Seed CSRF token before any mutating request.
  useEffect(() => {
    bootstrap().catch(() => {
      // Non-fatal — form will fail with a clear error if CSRF is missing.
    })
  }, [])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (state.phase === 'submitting') return

    setState({ phase: 'submitting' })
    try {
      await requestMagicLink(email.trim(), returnTo)
      setState({ phase: 'sent' })
    } catch (err) {
      const apiErr = err as ApiError
      setState({
        phase: 'error',
        message: apiErr?.payload?.message ?? 'Etwas ist schiefgelaufen. Bitte versuche es erneut.',
      })
    }
  }

  return (
    <div className="min-h-screen font-inter flex items-center justify-center px-4">
      <div
        className="w-full max-w-sm p-8 bg-vp-surface backdrop-blur-xl rounded-vp-xl border border-vp-border-subtle"
        style={{ boxShadow: '0 8px 32px rgba(0,0,0,0.08)' }}
      >
        {/* Back to wherever the user came from (board / main interface) */}
        <button
          type="button"
          onClick={() => (returnTo ? navigate(returnTo) : navigate(-1))}
          className="inline-flex items-center gap-1 text-[13px] text-vp-text-muted hover:text-vp-ink transition-colors mb-4 cursor-pointer"
        >
          <span aria-hidden="true">‹</span> Zurück
        </button>

        {/* Logo: hex icon + wordmark (matches Header brand mark) */}
        <div className="mb-6 flex flex-col items-center gap-3">
          <svg
            viewBox="-185 -205 370 410"
            width="44"
            height="49"
            fill="none"
            role="img"
            aria-label="Votepit"
          >
            <path d={LOGO_TOP} fill="var(--color-vp-vote-up)" />
            <path d={LOGO_BOT} fill="var(--color-vp-vote-down)" />
            <path d={LOGO_MID} fill="#084C37" />
            <path d={LOGO_DARK} fill="#05241A" />
          </svg>
          <span
            className="font-archivo font-extrabold text-[28px] leading-none tracking-[-0.025em]"
            aria-hidden="true"
          >
            <span className="text-vp-ink">Vote</span>
            <span className="text-vp-vote-down">pit</span>
          </span>
        </div>

        {state.phase === 'sent' ? (
          <SentState email={email} />
        ) : (
          <>
            <h1 className="font-archivo font-bold text-xl text-vp-ink mb-1 text-center">
              Anmelden
            </h1>
            <p className="text-vp-text-secondary text-sm mb-6 text-center">
              Wir senden dir einen Magic-Link per E-Mail.
            </p>

            <form onSubmit={handleSubmit} noValidate className="space-y-4">
              <TextInput
                label="E-Mail-Adresse"
                type="email"
                name="email"
                id="login-email"
                value={email}
                onChange={setEmail}
                placeholder="deine@email.de"
                required
                disabled={state.phase === 'submitting'}
                autoComplete="email"
                inputMode="email"
              />

              {state.phase === 'error' && (
                <p role="alert" className="text-[13px] text-vp-vote-down font-inter">
                  {state.message}
                </p>
              )}

              <Button
                type="submit"
                variant="primary"
                disabled={state.phase === 'submitting' || email.trim() === ''}
                className="w-full"
              >
                {state.phase === 'submitting' ? 'Wird gesendet…' : 'Magic-Link senden'}
              </Button>
            </form>
          </>
        )}
      </div>
    </div>
  )
}

function SentState({ email }: { email: string }) {
  return (
    <div className="text-center space-y-3">
      <div
        aria-hidden="true"
        className="mx-auto w-12 h-12 rounded-full bg-vp-vote-up/10 flex items-center justify-center text-vp-vote-up text-2xl"
      >
        ✉
      </div>
      <h2 className="font-archivo font-bold text-lg text-vp-ink">Link gesendet</h2>
      <p className="text-vp-text-secondary text-sm">
        Wir haben einen Magic-Link an <span className="font-medium text-vp-ink">{email}</span>{' '}
        gesendet. Schau in dein Postfach.
      </p>
      <p className="text-vp-text-muted text-xs">
        Kein Link angekommen?{' '}
        <Link to="/login" className="text-vp-accent underline hover:opacity-80 transition-opacity">
          Erneut versuchen
        </Link>
      </p>
    </div>
  )
}

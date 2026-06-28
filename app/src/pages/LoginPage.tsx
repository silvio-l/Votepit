import { useEffect, useState } from 'react'
import { useSearchParams, Link } from 'react-router-dom'
import { TextInput } from '../components/TextInput'
import { Button } from '../components/Button'
import { bootstrap, requestMagicLink } from '../lib/api'
import type { ApiError } from '../lib/api'

type PageState =
  | { phase: 'idle' }
  | { phase: 'submitting' }
  | { phase: 'sent' }
  | { phase: 'error'; message: string }

export default function LoginPage() {
  const [searchParams] = useSearchParams()
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
        message:
          apiErr?.payload?.message ??
          'Etwas ist schiefgelaufen. Bitte versuche es erneut.',
      })
    }
  }

  return (
    <div className="min-h-screen font-inter flex items-center justify-center px-4">
      <div
        className="w-full max-w-sm p-8 bg-vp-surface backdrop-blur-xl rounded-vp-xl border border-vp-border-subtle"
        style={{ boxShadow: '0 8px 32px rgba(0,0,0,0.08)' }}
      >
        {/* Logo / wordmark */}
        <div className="mb-6 text-center">
          <span
            className="font-archivo font-extrabold text-[28px] leading-none tracking-[-0.025em]"
            aria-label="Votepit"
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
                <p
                  role="alert"
                  className="text-[13px] text-vp-vote-down font-inter"
                >
                  {state.message}
                </p>
              )}

              <Button
                type="submit"
                variant="primary"
                disabled={state.phase === 'submitting' || email.trim() === ''}
                className="w-full"
              >
                {state.phase === 'submitting'
                  ? 'Wird gesendet…'
                  : 'Magic-Link senden'}
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
      <h2 className="font-archivo font-bold text-lg text-vp-ink">
        Link gesendet
      </h2>
      <p className="text-vp-text-secondary text-sm">
        Wir haben einen Magic-Link an{' '}
        <span className="font-medium text-vp-ink">{email}</span> gesendet.
        Schau in dein Postfach.
      </p>
      <p className="text-vp-text-muted text-xs">
        Kein Link angekommen?{' '}
        <Link
          to="/login"
          className="text-vp-accent underline hover:opacity-80 transition-opacity"
        >
          Erneut versuchen
        </Link>
      </p>
    </div>
  )
}

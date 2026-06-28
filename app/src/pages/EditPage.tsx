/**
 * EditPage — /{boardSlug}/idea/:ideaId/edit
 *
 * Pre-filled edit form for the idea author.
 * Non-authors are rejected server-side (403 on GET /edit) and shown an error.
 * Anon users are redirected to /login?r=… (return-to pattern).
 *
 * Anti-spam (same contract as SubmitPage):
 *   - honeypot field `website` (always '' — server rejects non-empty)
 *   - time-trap field `_form_at` (server-signed stamp from GET /ideas/{id}/edit)
 *
 * Error mapping: 422 `error.fields` → inline per-field messages via
 * TextInput/Textarea `error` prop.
 *
 * Design: Light Modern per Figma 96:282.
 */

import { Button, Header, Textarea, TextInput } from '@votepit/ui'
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import type { ApiError } from '../lib/api'
import { bootstrap, getIdeaForEdit, logout, updateIdea } from '../lib/api'

// ── Types ─────────────────────────────────────────────────────────────────────

type LoadPhase =
  | { tag: 'loading' }
  | { tag: 'error'; message: string }
  | { tag: 'ready'; boardName: string }

// ── Component ─────────────────────────────────────────────────────────────────

export default function EditPage() {
  const { boardSlug, ideaId } = useParams<{ boardSlug: string; ideaId: string }>()
  const navigate = useNavigate()
  const reduceMotion = useReducedMotion()

  const [loadPhase, setLoadPhase] = useState<LoadPhase>({ tag: 'loading' })
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [isAuthenticated, setIsAuthenticated] = useState(false)

  // Time-Trap stamp from server — kept in ref, not state (no re-render needed)
  const formAtRef = useRef<string>('')

  // ── Initialise: CSRF + idea data ──────────────────────────────────────────

  useEffect(() => {
    if (!boardSlug || !ideaId) return
    const slug: string = boardSlug
    const id: string = ideaId
    let cancelled = false

    async function init() {
      try {
        const [boot, editData] = await Promise.all([bootstrap(), getIdeaForEdit(slug, id)])
        if (cancelled) return

        setIsAuthenticated(boot.user !== null)
        setTitle(editData.idea.title)
        setBody(editData.idea.body)
        formAtRef.current = editData.form_at
        setLoadPhase({ tag: 'ready', boardName: editData.board.name })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr?.status === 401) {
          navigate(`/login?r=${encodeURIComponent(`/${slug}/idea/${id}/edit`)}`, {
            replace: true,
          })
          return
        }
        if (apiErr?.status === 403) {
          setLoadPhase({
            tag: 'error',
            message: 'Du hast keine Berechtigung, diese Idee zu bearbeiten.',
          })
          return
        }
        if (apiErr?.status === 404) {
          setLoadPhase({ tag: 'error', message: 'Diese Idee wurde nicht gefunden.' })
          return
        }
        setLoadPhase({
          tag: 'error',
          message: (err as ApiError)?.payload?.message ?? 'Seite konnte nicht geladen werden.',
        })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [boardSlug, ideaId, navigate])

  // ── Submit ────────────────────────────────────────────────────────────────

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting || !boardSlug || !ideaId || loadPhase.tag !== 'ready') return

    setSubmitting(true)
    setFieldErrors({})
    setGeneralError(null)

    try {
      await updateIdea(boardSlug, ideaId, {
        title: title.trim(),
        body: body.trim(),
        website: '', // honeypot — must be empty
        _form_at: formAtRef.current,
      })
      navigate(`/${boardSlug}/idea/${ideaId}`)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        setGeneralError(
          apiErr?.payload?.message ?? 'Etwas ist schiefgelaufen. Bitte versuche es erneut.',
        )
      }
      setSubmitting(false)
    }
  }

  // ── Logout ────────────────────────────────────────────────────────────────

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  // ── Skeleton states ───────────────────────────────────────────────────────

  if (loadPhase.tag === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <p className="text-[15px] font-inter text-vp-text-muted">Wird geladen…</p>
      </div>
    )
  }

  if (loadPhase.tag === 'error') {
    return (
      <div className="min-h-screen flex items-center justify-center px-4">
        <p role="alert" className="text-[15px] font-inter text-vp-vote-down">
          {loadPhase.message}
        </p>
      </div>
    )
  }

  const { boardName } = loadPhase
  const isSubmitDisabled = submitting || title.trim().length === 0

  // ── Main render ───────────────────────────────────────────────────────────

  return (
    <div className="min-h-screen">
      <Header
        logoHref={`/${boardSlug}`}
        boardName={boardName}
        isAuthenticated={isAuthenticated}
        onLogoutClick={handleLogout}
        onLoginClick={() => navigate('/login')}
      />

      <div className="vp-container pt-10 pb-12">
        <Link
          to={`/${boardSlug ?? ''}/idea/${ideaId ?? ''}`}
          className="text-[15px] font-inter text-vp-text-muted hover:text-vp-ink transition-colors inline-block"
        >
          ‹ Zurück zur Idee
        </Link>

        <div className="mt-6 mb-6 flex flex-col gap-2">
          <h1
            className="font-archivo font-bold text-[28px] leading-[1.14] text-vp-ink"
            style={{ fontVariationSettings: '"wdth" 100' }}
          >
            Idee bearbeiten
          </h1>
          <p className="text-[15px] font-inter leading-[1.48] text-vp-text-secondary">
            Beschreibe deine Idee in klarem Deutsch — Titel und Text, kein Markdown.
          </p>
        </div>

        <motion.div
          initial={reduceMotion ? false : { opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.25, ease: 'easeOut' }}
          className="bg-vp-surface border border-vp-border-subtle rounded-vp-lg shadow-vp-soft p-8"
        >
          <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-6">
            {/*
              Honeypot — display:none so bots see it but users don't interact with it.
              The server rejects any submission where `website` is non-empty.
            */}
            <div aria-hidden="true" style={{ display: 'none' }}>
              <label htmlFor="website-hp-edit">Website</label>
              <input
                id="website-hp-edit"
                type="text"
                name="website"
                autoComplete="off"
                tabIndex={-1}
                readOnly
                value=""
              />
            </div>

            <TextInput
              label="Titel"
              name="title"
              id="edit-title"
              value={title}
              onChange={setTitle}
              placeholder="Kurzer, prägnanter Titel"
              error={fieldErrors.title}
              hint={fieldErrors.title !== undefined ? undefined : '3–200 Zeichen'}
              required
              disabled={submitting}
              autoComplete="off"
            />

            <Textarea
              label="Beschreibung"
              name="body"
              id="edit-body"
              value={body}
              onChange={setBody}
              placeholder="Worum geht es? Was soll sich ändern?"
              error={fieldErrors.body}
              hint={fieldErrors.body !== undefined ? undefined : 'Mindestens 10 Zeichen'}
              required
              disabled={submitting}
              rows={6}
            />

            <AnimatePresence>
              {generalError !== null && (
                <motion.p
                  key="general-error"
                  role="alert"
                  initial={reduceMotion ? false : { opacity: 0, height: 0 }}
                  animate={{ opacity: 1, height: 'auto' }}
                  exit={{ opacity: 0, height: 0 }}
                  transition={{ duration: 0.18 }}
                  className="text-[13px] font-inter text-vp-vote-down"
                >
                  {generalError}
                </motion.p>
              )}
            </AnimatePresence>

            <div>
              <Button
                type="submit"
                variant="primary"
                disabled={isSubmitDisabled}
                aria-busy={submitting}
              >
                {submitting ? 'Wird gespeichert…' : 'Änderungen speichern'}
              </Button>
            </div>
          </form>
        </motion.div>
      </div>
    </div>
  )
}

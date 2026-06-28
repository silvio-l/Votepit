/**
 * AdminPage — /admin/boards/:boardSlug
 *
 * Board-Admin-Fläche: Branding + Moderation (Issue 14).
 *
 * Auth gate:
 *   - Anon  → redirect to /login?r=…
 *   - Non-admin → "Kein Zugriff" message (no forms rendered)
 *   - Admin  → two forms: Branding + Moderation
 *
 * Branding: primary_color, secondary_color, logo_url.
 *   Only --vp-* brand tokens; semantic vote/status tokens are intentionally
 *   NOT editable here so Up/Down meaning stays readable across all boards.
 *
 * Moderation: toggle (enabled/disabled) + custom blocklist (add / remove).
 *   Word-add reloads the list so IDs are accurate for subsequent removes.
 *
 * Design: Light Modern — token-bound, frosted-glass card, Archivo/Inter,
 *   warm-neutral, no hard borders.
 */

import { AnimatePresence, motion, useReducedMotion } from 'framer-motion'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Button, Header, PageShell, TextInput } from '../components'
import type { ApiError, ModerationWord } from '../lib/api'
import {
  bootstrap,
  getAdminBranding,
  getAdminModeration,
  logout,
  saveAdminBranding,
  saveAdminModeration,
} from '../lib/api'

// ── Types ─────────────────────────────────────────────────────────────────────

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready'; boardName: string }

// ── Sub-component: SuccessBanner ──────────────────────────────────────────────

function SuccessBanner({ visible, message }: { visible: boolean; message: string }) {
  const reduceMotion = useReducedMotion()
  return (
    <AnimatePresence>
      {visible && (
        <motion.p
          role="status"
          aria-live="polite"
          initial={reduceMotion ? false : { opacity: 0, height: 0 }}
          animate={{ opacity: 1, height: 'auto' }}
          exit={{ opacity: 0, height: 0 }}
          transition={{ duration: 0.18 }}
          className="text-[13px] font-inter text-vp-status-done"
        >
          {message}
        </motion.p>
      )}
    </AnimatePresence>
  )
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function AdminPage() {
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)

  // ── Branding state ─────────────────────────────────────────────────────────
  const [primaryColor, setPrimaryColor] = useState('')
  const [secondaryColor, setSecondaryColor] = useState('')
  const [logoUrl, setLogoUrl] = useState('')
  const [brandingErrors, setBrandingErrors] = useState<Record<string, string>>({})
  const [brandingGeneralError, setBrandingGeneralError] = useState<string | null>(null)
  const [brandingSaving, setBrandingSaving] = useState(false)
  const [brandingSuccess, setBrandingSuccess] = useState(false)

  // ── Moderation state ───────────────────────────────────────────────────────
  const [modEnabled, setModEnabled] = useState(true)
  const [words, setWords] = useState<ModerationWord[]>([])
  const [newWord, setNewWord] = useState('')
  const [modErrors, setModErrors] = useState<Record<string, string>>({})
  const [modGeneralError, setModGeneralError] = useState<string | null>(null)
  const [modSaving, setModSaving] = useState(false)
  const [modSuccess, setModSuccess] = useState<string | null>(null)

  // ── Initialise ─────────────────────────────────────────────────────────────

  useEffect(() => {
    if (!boardSlug) return
    const slug: string = boardSlug
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(`/admin/boards/${slug}`)}`, {
            replace: true,
          })
          return
        }

        if (!boot.user.is_admin) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }

        setIsAuthenticated(true)

        const [branding, moderation] = await Promise.all([
          getAdminBranding(slug),
          getAdminModeration(slug),
        ])

        if (cancelled) return

        setPrimaryColor(branding.primary_color ?? '')
        setSecondaryColor(branding.secondary_color ?? '')
        setLogoUrl(branding.logo_url ?? '')
        setModEnabled(moderation.moderation_enabled)
        setWords(moderation.words)
        setPageState({ phase: 'ready', boardName: branding.board_name })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(`/admin/boards/${slug}`)}`, {
            replace: true,
          })
          return
        }
        if (apiErr.name === 'ApiError' && apiErr.status === 403) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }
        const msg =
          (apiErr as ApiError)?.payload?.message ??
          (err as Error)?.message ??
          'Seite konnte nicht geladen werden.'
        setPageState({ phase: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [boardSlug, navigate])

  // ── Handlers ───────────────────────────────────────────────────────────────

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleBrandingSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || brandingSaving) return

    setBrandingSaving(true)
    setBrandingErrors({})
    setBrandingGeneralError(null)
    setBrandingSuccess(false)

    try {
      await saveAdminBranding(boardSlug, {
        primary_color: primaryColor,
        secondary_color: secondaryColor,
        logo_url: logoUrl,
      })
      setBrandingSuccess(true)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setBrandingErrors(fields)
      } else {
        setBrandingGeneralError(
          apiErr?.payload?.message ?? 'Speichern fehlgeschlagen. Bitte versuche es erneut.',
        )
      }
    } finally {
      setBrandingSaving(false)
    }
  }

  const handleToggleSave = async () => {
    if (!boardSlug || modSaving) return

    setModSaving(true)
    setModSuccess(null)
    setModGeneralError(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'toggle',
        moderation_enabled: modEnabled ? '1' : '0',
      })
      setModSuccess('Moderation gespeichert.')
    } catch (err) {
      const apiErr = err as ApiError
      setModGeneralError(
        apiErr?.payload?.message ?? 'Speichern fehlgeschlagen. Bitte versuche es erneut.',
      )
    } finally {
      setModSaving(false)
    }
  }

  const handleAddWord = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || modSaving || newWord.trim() === '') return

    setModSaving(true)
    setModErrors({})
    setModSuccess(null)
    setModGeneralError(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'add',
        new_word: newWord.trim(),
      })
      // Reload to get accurate IDs for subsequent removes.
      const mod = await getAdminModeration(boardSlug)
      setWords(mod.words)
      setNewWord('')
      setModSuccess('Wort hinzugefügt.')
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setModErrors(fields)
      } else {
        setModGeneralError(
          apiErr?.payload?.message ?? 'Hinzufügen fehlgeschlagen. Bitte versuche es erneut.',
        )
      }
    } finally {
      setModSaving(false)
    }
  }

  const handleRemoveWord = async (wordId: number) => {
    if (!boardSlug || modSaving) return

    setModSaving(true)
    setModSuccess(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'remove',
        word_id: wordId,
      })
      setWords((prev) => prev.filter((w) => w.id !== wordId))
      setModSuccess('Wort entfernt.')
    } catch {
      // Silent fail — word may already be gone; list stays as-is.
    } finally {
      setModSaving(false)
    }
  }

  // ── Skeleton states ────────────────────────────────────────────────────────

  const header = (
    <Header
      logoHref="/"
      isAuthenticated={isAuthenticated}
      onLogoutClick={handleLogout}
      onLoginClick={() => navigate('/login')}
    />
  )

  if (pageState.phase === 'loading') {
    return (
      <PageShell header={header}>
        <p
          className="text-vp-text-muted text-sm text-center py-20"
          aria-live="polite"
          aria-busy="true"
        >
          Wird geladen…
        </p>
      </PageShell>
    )
  }

  if (pageState.phase === 'access_denied') {
    return (
      <PageShell header={header}>
        <div className="text-center py-20" role="alert">
          <h1 className="font-archivo font-bold text-[24px] text-vp-ink mb-3">Kein Zugriff</h1>
          <p className="text-[15px] font-inter text-vp-text-secondary">
            Diese Seite ist nur für Board-Administratoren zugänglich.
          </p>
        </div>
      </PageShell>
    )
  }

  if (pageState.phase === 'error') {
    return (
      <PageShell header={header}>
        <div className="text-center py-20">
          <p role="alert" className="text-[15px] font-inter text-vp-vote-down">
            {pageState.message}
          </p>
        </div>
      </PageShell>
    )
  }

  const { boardName } = pageState

  // ── Main render ────────────────────────────────────────────────────────────

  return (
    <PageShell header={header}>
      <Link
        to={`/${boardSlug ?? ''}`}
        className="text-[15px] font-inter text-vp-text-muted hover:text-vp-ink transition-colors inline-block"
      >
        ‹ Zurück zum Board
      </Link>

      <div className="mt-6 mb-8">
        <h1
          className="font-archivo font-bold text-[28px] leading-[1.14] text-vp-ink"
          style={{ fontVariationSettings: '"wdth" 100' }}
        >
          Admin: {boardName}
        </h1>
        <p className="mt-1 text-[15px] font-inter text-vp-text-secondary">
          Branding und Inhaltsfilter für dieses Board verwalten.
        </p>
      </div>

      <div className="flex flex-col gap-8">
        {/* ── Branding ──────────────────────────────────────────────────── */}
        <section
          aria-labelledby="branding-heading"
          className="bg-vp-surface-frost border border-vp-border-subtle rounded-vp-lg p-6 md:p-8"
        >
          <h2
            id="branding-heading"
            className="font-archivo font-semibold text-[18px] text-vp-ink mb-1"
          >
            Branding
          </h2>
          <p className="text-[13px] font-inter text-vp-text-muted mb-6">
            Nur Marken-Tokens — semantische Vote-/Status-Farben bleiben unberührt.
          </p>

          <form onSubmit={handleBrandingSave} noValidate className="flex flex-col gap-5">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <TextInput
                label="Primärfarbe"
                name="primary_color"
                id="admin-primary-color"
                value={primaryColor}
                onChange={setPrimaryColor}
                placeholder="#1fa890"
                error={brandingErrors.primary_color}
                hint={
                  brandingErrors.primary_color !== undefined ? undefined : 'Hex-Code, z. B. #1fa890'
                }
                disabled={brandingSaving}
                autoComplete="off"
              />

              <TextInput
                label="Sekundärfarbe"
                name="secondary_color"
                id="admin-secondary-color"
                value={secondaryColor}
                onChange={setSecondaryColor}
                placeholder="#15161a"
                error={brandingErrors.secondary_color}
                hint={
                  brandingErrors.secondary_color !== undefined
                    ? undefined
                    : 'Hex-Code, z. B. #15161a'
                }
                disabled={brandingSaving}
                autoComplete="off"
              />
            </div>

            <TextInput
              label="Logo-URL"
              name="logo_url"
              id="admin-logo-url"
              value={logoUrl}
              onChange={setLogoUrl}
              placeholder="/assets/logo.svg"
              error={brandingErrors.logo_url}
              hint={
                brandingErrors.logo_url !== undefined ? undefined : 'Relativer Pfad oder HTTPS-URL'
              }
              disabled={brandingSaving}
              autoComplete="off"
            />

            <AnimatePresence>
              {brandingGeneralError !== null && (
                <motion.p
                  key="branding-error"
                  role="alert"
                  initial={{ opacity: 0, height: 0 }}
                  animate={{ opacity: 1, height: 'auto' }}
                  exit={{ opacity: 0, height: 0 }}
                  transition={{ duration: 0.18 }}
                  className="text-[13px] font-inter text-vp-vote-down"
                >
                  {brandingGeneralError}
                </motion.p>
              )}
            </AnimatePresence>

            <SuccessBanner visible={brandingSuccess} message="Branding gespeichert." />

            <div>
              <Button
                type="submit"
                variant="primary"
                disabled={brandingSaving}
                aria-busy={brandingSaving}
              >
                {brandingSaving ? 'Wird gespeichert…' : 'Branding speichern'}
              </Button>
            </div>
          </form>
        </section>

        {/* ── Moderation ────────────────────────────────────────────────── */}
        <section
          aria-labelledby="moderation-heading"
          className="bg-vp-surface-frost border border-vp-border-subtle rounded-vp-lg p-6 md:p-8"
        >
          <h2
            id="moderation-heading"
            className="font-archivo font-semibold text-[18px] text-vp-ink mb-1"
          >
            Moderation
          </h2>
          <p className="text-[13px] font-inter text-vp-text-muted mb-6">
            Inhaltsfilter und board-eigene Blockliste.
          </p>

          {/* Toggle */}
          <div className="flex items-center gap-4 mb-6">
            <label
              htmlFor="admin-mod-toggle"
              className="flex items-center gap-3 cursor-pointer select-none"
            >
              <input
                id="admin-mod-toggle"
                type="checkbox"
                checked={modEnabled}
                onChange={(e) => setModEnabled(e.target.checked)}
                disabled={modSaving}
                className="w-4 h-4 accent-vp-accent cursor-pointer disabled:cursor-not-allowed"
              />
              <span className="text-[15px] font-inter text-vp-ink">Inhaltsfilter aktiv</span>
            </label>

            <Button
              type="button"
              variant="secondary"
              onClick={handleToggleSave}
              disabled={modSaving}
              aria-busy={modSaving}
            >
              Speichern
            </Button>
          </div>

          {/* Word list */}
          {words.length > 0 && (
            <div className="mb-5">
              <p className="text-[13px] font-medium text-vp-text-secondary mb-2">
                Blockliste ({words.length})
              </p>
              <ul className="flex flex-col gap-1" aria-label="Blockliste">
                {words.map((w) => (
                  <li
                    key={w.id}
                    className="flex items-center justify-between gap-3 px-3 py-2 bg-vp-surface rounded-vp-md border border-vp-border-subtle"
                  >
                    <span className="text-[14px] font-mono text-vp-ink">{w.word}</span>
                    <button
                      type="button"
                      onClick={() => void handleRemoveWord(w.id)}
                      disabled={modSaving}
                      aria-label={`Wort „${w.word}" entfernen`}
                      className="text-[12px] font-inter text-vp-vote-down hover:opacity-70 transition-opacity disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                      Entfernen
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Add word form */}
          <form
            onSubmit={handleAddWord}
            noValidate
            className="flex items-end gap-3"
            aria-label="Wort hinzufügen"
          >
            <div className="flex-1">
              <TextInput
                label="Neues Wort"
                name="new_word"
                id="admin-new-word"
                value={newWord}
                onChange={setNewWord}
                placeholder="Begriff eingeben…"
                error={modErrors.new_word}
                disabled={modSaving}
                autoComplete="off"
              />
            </div>
            <div className="pb-[1px]">
              <Button
                type="submit"
                variant="secondary"
                disabled={modSaving || newWord.trim() === ''}
                aria-busy={modSaving}
              >
                Hinzufügen
              </Button>
            </div>
          </form>

          <AnimatePresence>
            {modGeneralError !== null && (
              <motion.p
                key="mod-error"
                role="alert"
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                exit={{ opacity: 0, height: 0 }}
                transition={{ duration: 0.18 }}
                className="mt-3 text-[13px] font-inter text-vp-vote-down"
              >
                {modGeneralError}
              </motion.p>
            )}
          </AnimatePresence>

          {modSuccess !== null && <SuccessBanner visible message={modSuccess} />}
        </section>
      </div>
    </PageShell>
  )
}

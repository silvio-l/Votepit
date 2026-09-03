/**
 * SubmitPage — /{boardSlug}/submit
 *
 * Renders the "submit a new idea" form for authenticated users.
 * Anon users are redirected to /login?r=… (return-to pattern, #10).
 *
 * Anti-spam:
 *   - honeypot field `website` (always '' — server rejects non-empty)
 *   - time-trap field `_form_at` (server-signed stamp from GET /ideas/new)
 *
 * Error mapping: 422 `error.fields` → inline per-field messages via
 * TextInput/Textarea `error` prop.
 */

import {
  Alert,
  Button,
  buttonClassName,
  ErrorState,
  LoadingState,
  PageHeader,
  PageShell,
  Section,
  Textarea,
  TextInput,
} from '@votepit/ui'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { LocalizedHeader, ScopeLabel } from '../components/LocalizedHeader'
import { accountPath } from '../lib/accountContext'
import type { ApiError, DuplicateCandidate, User } from '../lib/api'
import { bootstrap, createIdea, getSubmitForm, logout, searchDuplicates } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

/** Debounce delay (ms) before firing a duplicate-search request while typing. */
const DUPLICATE_SEARCH_DEBOUNCE_MS = 350

/** Below this trimmed length, don't bother searching (too little signal). */
const MIN_DUPLICATE_SEARCH_LENGTH = 3

type LoadPhase =
  | { tag: 'loading' }
  | { tag: 'error'; message: string }
  | { tag: 'ready'; boardName: string }

export default function SubmitPage() {
  const t = useT('submitPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()

  const [loadPhase, setLoadPhase] = useState<LoadPhase>({ tag: 'loading' })
  const [user, setUser] = useState<User | null>(null)
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // As-you-type duplicate hint — surfacing only, never blocks submit.
  const [duplicates, setDuplicates] = useState<DuplicateCandidate[]>([])
  const [duplicatesDismissed, setDuplicatesDismissed] = useState(false)

  // Time-trap stamp from server — kept in a ref, not state (no re-render needed).
  const formAtRef = useRef<string>('')

  useEffect(() => {
    if (!boardSlug) return
    const slug: string = boardSlug
    let cancelled = false

    async function init() {
      try {
        const [boot, formData] = await Promise.all([bootstrap(), getSubmitForm(slug)])
        if (cancelled) return

        if (!formData.is_authenticated) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/${slug}/submit`))}`, {
            replace: true,
          })
          return
        }

        formAtRef.current = formData.form_at
        setUser(boot.user)
        setLoadPhase({ tag: 'ready', boardName: formData.board.name })
      } catch (err) {
        if (cancelled) return
        const msg =
          (err as ApiError)?.payload?.message ?? (err as ApiError)?.message ?? t('loadError')
        setLoadPhase({ tag: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [boardSlug, navigate, t])

  useEffect(() => {
    if (!boardSlug || loadPhase.tag !== 'ready') return

    const trimmed = title.trim()
    setDuplicatesDismissed(false)

    if (trimmed.length < MIN_DUPLICATE_SEARCH_LENGTH) {
      setDuplicates([])
      return
    }

    let cancelled = false
    const timer = setTimeout(() => {
      void searchDuplicates(boardSlug, trimmed)
        .then((res) => {
          if (!cancelled) setDuplicates(res.candidates)
        })
        .catch(() => {
          // Non-critical hint — a failed lookup just means no hint, never an error.
          if (!cancelled) setDuplicates([])
        })
    }, DUPLICATE_SEARCH_DEBOUNCE_MS)

    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [title, boardSlug, loadPhase.tag])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting || !boardSlug || loadPhase.tag !== 'ready') return

    setSubmitting(true)
    setFieldErrors({})
    setGeneralError(null)

    try {
      const result = await createIdea(boardSlug, {
        title: title.trim(),
        body: body.trim(),
        website: '', // honeypot — must be empty
        _form_at: formAtRef.current,
      })
      navigate(accountPath(`/${boardSlug}/idea/${result.id}`))
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        setGeneralError(apiErr?.payload?.message ?? tCommon('state.error'))
      }
      setSubmitting(false)
    }
  }

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const boardHref = accountPath(`/${boardSlug ?? ''}`)
  const boardName = loadPhase.tag === 'ready' ? loadPhase.boardName : undefined

  const header = (
    <LocalizedHeader
      logoHref={boardHref}
      basePath={boardHref}
      boardSlug={boardSlug}
      isAuthenticated={user !== null}
      user={user}
      onLogoutClick={handleLogout}
      onLoginClick={() => navigate(`/login?r=${encodeURIComponent(boardHref)}`)}
      scope={<ScopeLabel section={boardName} />}
    />
  )

  if (loadPhase.tag === 'loading') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <LoadingState label={t('loading')} rows={4} />
      </PageShell>
    )
  }

  if (loadPhase.tag === 'error') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <ErrorState
          title={tCommon('state.errorTitle')}
          description={loadPhase.message}
          action={
            <Link to={boardHref} className={buttonClassName('secondary')}>
              <ArrowLeft size={16} strokeWidth={2} aria-hidden="true" />
              {t('backToList')}
            </Link>
          }
        />
      </PageShell>
    )
  }

  const isSubmitDisabled = submitting || title.trim().length === 0

  return (
    <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
      <PageHeader
        title={t('heading')}
        description={t('subtitle')}
        back={
          <Link
            to={boardHref}
            className="inline-flex items-center gap-1.5 text-vp-text-secondary hover:text-vp-ink hover:underline transition-colors duration-150"
          >
            <ArrowLeft size={14} strokeWidth={2} aria-hidden="true" />
            {t('backToList')}
          </Link>
        }
      />

      <Section
        emphasis="ruled"
        footer={
          <>
            <Button
              type="submit"
              form="submit-idea-form"
              variant="primary"
              disabled={isSubmitDisabled}
              loading={submitting}
              aria-busy={submitting}
            >
              {submitting ? t('submitting') : t('submit')}
            </Button>
            <Link
              to={boardHref}
              className="text-vp-sm text-vp-text-secondary hover:text-vp-ink hover:underline"
            >
              {tCommon('action.cancel')}
            </Link>
          </>
        }
      >
        <form
          id="submit-idea-form"
          onSubmit={handleSubmit}
          noValidate
          className="flex flex-col gap-5 py-1"
        >
          {/*
            Honeypot — display:none so bots see it but users never interact with it.
            The server rejects any submission where `website` is non-empty.
          */}
          <div aria-hidden="true" style={{ display: 'none' }}>
            <label htmlFor="website-hp">{t('honeypotLabel')}</label>
            <input
              id="website-hp"
              type="text"
              name="website"
              autoComplete="off"
              tabIndex={-1}
              readOnly
              value=""
            />
          </div>

          <TextInput
            label={t('titleLabel')}
            name="title"
            id="submit-title"
            value={title}
            onChange={setTitle}
            placeholder={t('titlePlaceholder')}
            error={fieldErrors.title}
            hint={fieldErrors.title !== undefined ? undefined : t('titleHint')}
            required
            disabled={submitting}
            autoComplete="off"
          />

          {duplicates.length > 0 && !duplicatesDismissed && (
            <Alert tone="info" title={t('duplicateHintQuestion')}>
              <p className="text-vp-text-secondary">{t('duplicateHintLead')}</p>
              <ul className="mt-2 flex flex-col gap-1.5">
                {duplicates.map((candidate) => (
                  <li key={candidate.id} className="flex flex-wrap items-baseline gap-x-2">
                    <Link
                      to={accountPath(`/${boardSlug}/idea/${candidate.id}`)}
                      target="_blank"
                      rel="noreferrer"
                      className="text-vp-base text-vp-ink underline hover:no-underline"
                    >
                      {candidate.title}
                    </Link>
                    <span className="font-mono-num text-vp-xs text-vp-text-muted">
                      {t('votesCount', { count: candidate.up_count - candidate.down_count })}
                    </span>
                  </li>
                ))}
              </ul>
              <div className="mt-3">
                <Button variant="secondary" size="sm" onClick={() => setDuplicatesDismissed(true)}>
                  {t('submitAnywayButton')}
                </Button>
              </div>
            </Alert>
          )}

          <Textarea
            label={t('bodyLabel')}
            name="body"
            id="submit-body"
            value={body}
            onChange={setBody}
            placeholder={t('bodyPlaceholder')}
            error={fieldErrors.body}
            hint={fieldErrors.body !== undefined ? undefined : t('bodyHint')}
            required
            disabled={submitting}
            rows={6}
          />

          {generalError !== null && <Alert tone="error">{generalError}</Alert>}
        </form>
      </Section>
    </PageShell>
  )
}

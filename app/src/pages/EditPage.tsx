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
import type { ApiError, User } from '../lib/api'
import { bootstrap, getIdeaForEdit, logout, updateIdea } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

type LoadPhase =
  | { tag: 'loading' }
  | { tag: 'error'; message: string }
  | { tag: 'ready'; boardName: string }

export default function EditPage() {
  const { boardSlug, ideaId } = useParams<{ boardSlug: string; ideaId: string }>()
  const navigate = useNavigate()
  const t = useT('editPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [loadPhase, setLoadPhase] = useState<LoadPhase>({ tag: 'loading' })
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  // Time-trap stamp from server — kept in a ref, not state (no re-render needed).
  const formAtRef = useRef<string>('')

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
        setUser(boot.user)
        setTitle(editData.idea.title)
        setBody(editData.idea.body)
        formAtRef.current = editData.form_at
        setLoadPhase({ tag: 'ready', boardName: editData.board.name })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr?.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/${slug}/idea/${id}/edit`))}`, {
            replace: true,
          })
          return
        }
        if (apiErr?.status === 403) {
          setLoadPhase({ tag: 'error', message: t('forbiddenError') })
          return
        }
        if (apiErr?.status === 404) {
          setLoadPhase({ tag: 'error', message: t('notFoundError') })
          return
        }
        setLoadPhase({
          tag: 'error',
          message: (err as ApiError)?.payload?.message ?? t('loadError'),
        })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [boardSlug, ideaId, navigate, t])

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
      navigate(accountPath(`/${boardSlug}/idea/${ideaId}`))
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        setGeneralError(apiErr?.payload?.message ?? t('genericError'))
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
  const ideaHref = accountPath(`/${boardSlug ?? ''}/idea/${ideaId ?? ''}`)
  const boardName = loadPhase.tag === 'ready' ? loadPhase.boardName : undefined

  const header = (
    <LocalizedHeader
      logoHref={boardHref}
      basePath={boardHref}
      boardSlug={boardSlug}
      isAuthenticated={isAuthenticated}
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
              {t('backToBoard')}
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
        description={t('subheading')}
        back={
          <Link
            to={ideaHref}
            className="inline-flex items-center gap-1.5 text-vp-text-secondary hover:text-vp-ink hover:underline transition-colors duration-150"
          >
            <ArrowLeft size={14} strokeWidth={2} aria-hidden="true" />
            {t('backToIdea')}
          </Link>
        }
      />

      <Section
        emphasis="ruled"
        footer={
          <>
            <Button
              type="submit"
              form="edit-idea-form"
              variant="primary"
              disabled={isSubmitDisabled}
              loading={submitting}
              aria-busy={submitting}
            >
              {submitting ? t('saving') : t('saveChanges')}
            </Button>
            <Link
              to={ideaHref}
              className="text-vp-sm text-vp-text-secondary hover:text-vp-ink hover:underline"
            >
              {tCommon('action.cancel')}
            </Link>
          </>
        }
      >
        <form
          id="edit-idea-form"
          onSubmit={handleSubmit}
          noValidate
          className="flex flex-col gap-5 py-1"
        >
          {/*
            Honeypot — display:none so bots see it but users never interact with it.
            The server rejects any submission where `website` is non-empty.
          */}
          <div aria-hidden="true" style={{ display: 'none' }}>
            <label htmlFor="website-hp-edit">{t('websiteHoneypotLabel')}</label>
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
            label={t('titleLabel')}
            name="title"
            id="edit-title"
            value={title}
            onChange={setTitle}
            placeholder={t('titlePlaceholder')}
            error={fieldErrors.title}
            hint={fieldErrors.title !== undefined ? undefined : t('titleHint')}
            required
            disabled={submitting}
            autoComplete="off"
          />

          <Textarea
            label={t('bodyLabel')}
            name="body"
            id="edit-body"
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

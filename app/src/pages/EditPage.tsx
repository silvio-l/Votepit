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
  BackLink,
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
import { MarkdownToolbar } from '../components/MarkdownToolbar'
import { useVoterPreview } from '../hooks/useVoterPreview'
import { accountPath } from '../lib/accountContext'
import type { ApiError, User } from '../lib/api'
import { bootstrap, getIdeaForEdit, logout, updateIdea } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

type ErrorKind = 'failure' | 'denied' | 'missing' | 'expired'

type LoadPhase =
  | { tag: 'loading' }
  | { tag: 'error'; message: string; kind: ErrorKind }
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
  const bodyRef = useRef<HTMLTextAreaElement>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [viewAsVoter, setViewAsVoterPreview] = useVoterPreview()

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
          setLoadPhase({ tag: 'error', message: t('forbiddenError'), kind: 'denied' })
          return
        }
        if (apiErr?.status === 404) {
          setLoadPhase({ tag: 'error', message: t('notFoundError'), kind: 'missing' })
          return
        }
        if (apiErr?.status === 422 && apiErr.payload?.key === 'edit_window_expired') {
          setLoadPhase({ tag: 'error', message: t('editWindowExpiredError'), kind: 'expired' })
          return
        }
        setLoadPhase({
          tag: 'error',
          message: (err as ApiError)?.payload?.message ?? t('loadError'),
          kind: 'failure',
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
      if (apiErr?.status === 422 && apiErr.payload?.key === 'edit_window_expired') {
        setLoadPhase({ tag: 'error', message: t('editWindowExpiredError'), kind: 'expired' })
        setSubmitting(false)
        return
      }
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
      voterPreview={viewAsVoter}
      onVoterPreviewChange={setViewAsVoterPreview}
    />
  )

  if (loadPhase.tag === 'loading') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <LoadingState label={t('loading')} rows={4} />
      </PageShell>
    )
  }

  if (loadPhase.tag === 'error') {
    const errorTitle =
      loadPhase.kind === 'denied'
        ? tCommon('state.accessDeniedTitle')
        : loadPhase.kind === 'missing'
          ? tCommon('state.notFoundTitle')
          : loadPhase.kind === 'expired'
            ? t('editWindowExpiredTitle')
            : tCommon('state.errorTitle')
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <ErrorState
          kind={loadPhase.kind === 'expired' ? 'denied' : loadPhase.kind}
          title={errorTitle}
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
    <PageShell header={header} legalLinks={legalLinksFor(language)}>
      <PageHeader
        title={t('heading')}
        description={t('subheading')}
        back={
          <BackLink as={Link} to={ideaHref}>
            {t('backToIdea')}
          </BackLink>
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
            <Link to={ideaHref} className={buttonClassName('link')}>
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
            ref={bodyRef}
            label={t('bodyLabel')}
            name="body"
            id="edit-body"
            labelEnd={<MarkdownToolbar textareaRef={bodyRef} value={body} onChange={setBody} />}
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

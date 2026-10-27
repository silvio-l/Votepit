/**
 * AppealPage — /appeal/:moderationActionId (self-host) or
 * /{accountSlug}/appeal/:moderationActionId (cloud).
 *
 * board-operator-compliance issue 09 — the "Appeal this removal" affordance
 * for the original author of removed content. Reached primarily via the
 * link in the removal-notification email
 * (CommentModerationAction/IdeaModerationAction::appealLinkPath()), since
 * removed content itself is soft-deleted and no longer browsable in the
 * board UI — the emailed link, carrying the moderation_action_id, is the
 * only way an author finds their way back here.
 *
 * Auth gate: any logged-in user reaches the form (return-to /login?r=…,
 * same pattern as SubmitPage); the backend's own author check (only the
 * original author may appeal) is what actually enforces this — a non-author
 * who happens to be logged in gets a 403 rendered as an error state here.
 *
 * Error mapping:
 *   - 404 → "this removal could not be found" (also covers cross-account,
 *     per the "invisible = not found" convention).
 *   - 403 → "only the original author can appeal this removal."
 *   - 409 → "an appeal for this removal is already pending review."
 *   - 422 → inline field error on the message textarea.
 */

import {
  Button,
  ButtonLink,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  PageShell,
  Section,
  TextArea,
} from '@votepit/ui/ds'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { LocalizedHeader, ScopeLabel } from '../components/LocalizedHeader'
import { RouterButtonLink } from '../components/RouterButtonLink'
import { accountPath } from '../lib/accountContext'
import type { ApiError, User } from '../lib/api'
import { bootstrap, logout, submitAppeal } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

type LoadPhase =
  | { tag: 'loading' }
  | { tag: 'ready' }
  | { tag: 'submitted' }
  | { tag: 'error'; message: string }

const MAX_MESSAGE_LENGTH = 4000

export default function AppealPage() {
  const t = useT('appealPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const { moderationActionId } = useParams<{ moderationActionId: string }>()
  const navigate = useNavigate()

  const [user, setUser] = useState<User | null>(null)
  const [phase, setPhase] = useState<LoadPhase>({ tag: 'loading' })
  const [message, setMessage] = useState('')
  const [fieldError, setFieldError] = useState<string | undefined>(undefined)
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const returnTo = accountPath(`/appeal/${moderationActionId ?? ''}`)

  useEffect(() => {
    let cancelled = false
    bootstrap()
      .then((boot) => {
        if (cancelled) return
        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(returnTo)}`, { replace: true })
          return
        }
        setUser(boot.user)
        setPhase({ tag: 'ready' })
      })
      .catch(() => {
        if (!cancelled) setPhase({ tag: 'error', message: t('loadError') })
      })
    return () => {
      cancelled = true
    }
  }, [navigate, returnTo, t])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    const idNum = Number(moderationActionId)
    if (submitting || !Number.isFinite(idNum) || idNum <= 0) return

    setSubmitting(true)
    setFieldError(undefined)
    setGeneralError(null)

    try {
      await submitAppeal(idNum, message.trim())
      setPhase({ tag: 'submitted' })
    } catch (err) {
      const apiErr = err as ApiError
      if (apiErr?.name === 'ApiError' && apiErr.status === 404) {
        setPhase({ tag: 'error', message: t('notFoundError') })
      } else if (apiErr?.name === 'ApiError' && apiErr.status === 403) {
        setPhase({ tag: 'error', message: t('notAuthorError') })
      } else if (apiErr?.name === 'ApiError' && apiErr.status === 409) {
        setGeneralError(t('alreadyPendingError'))
      } else if (apiErr?.name === 'ApiError' && apiErr.status === 422) {
        const fieldMsg = apiErr.payload?.fields?.message
        if (fieldMsg !== undefined) {
          setFieldError(fieldMsg)
        } else {
          setGeneralError(apiErr.payload?.message ?? t('submitFailed'))
        }
      } else {
        setGeneralError(apiErr?.payload?.message ?? t('submitFailed'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  const homeHref = accountPath('/')

  const header = (
    <LocalizedHeader
      logoHref={homeHref}
      basePath={homeHref}
      isAuthenticated={user !== null}
      user={user}
      onLogoutClick={handleLogout}
      onLoginClick={() => navigate(`/login?r=${encodeURIComponent(returnTo)}`)}
      scope={<ScopeLabel section={t('scopeLabel')} />}
    />
  )

  if (phase.tag === 'loading') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <LoadingState label={t('loading')} rows={4} />
      </PageShell>
    )
  }

  if (phase.tag === 'error') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <ErrorState
          title={tCommon('state.errorTitle')}
          description={phase.message}
          action={
            <ButtonLink
              href={homeHref}
              linkAs={RouterButtonLink}
              variant="secondary"
              testId="appeal-back-to-board"
            >
              {t('backToBoard')}
            </ButtonLink>
          }
        />
      </PageShell>
    )
  }

  if (phase.tag === 'submitted') {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <PageHeader title={t('submittedTitle')} description={t('submittedBody')} />
      </PageShell>
    )
  }

  const isSubmitDisabled = submitting || message.trim().length === 0

  return (
    <PageShell header={header} legalLinks={legalLinksFor(language)}>
      <PageHeader title={t('heading')} description={t('subtitle')} />

      <Section
        testId="appeal-form-section"
        emphasis="ruled"
        footer={
          <Button
            type="submit"
            form="appeal-form"
            variant="primary"
            testId="appeal-submit"
            disabled={isSubmitDisabled}
            loading={submitting}
          >
            {submitting ? t('submitting') : t('submit')}
          </Button>
        }
      >
        <form
          id="appeal-form"
          onSubmit={handleSubmit}
          noValidate
          className="flex flex-col gap-5 py-1"
        >
          <TextArea
            testId="appeal-message"
            label={t('messageLabel')}
            name="message"
            id="appeal-message"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            placeholder={t('messagePlaceholder')}
            error={fieldError}
            hint={
              fieldError !== undefined ? undefined : t('messageHint', { count: message.length })
            }
            required
            disabled={submitting}
            rows={6}
            maxLength={MAX_MESSAGE_LENGTH}
          />

          {generalError !== null && <InlineNotice tone="error">{generalError}</InlineNotice>}
        </form>
      </Section>
    </PageShell>
  )
}

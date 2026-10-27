/**
 * NewIdeaSheet — the "New idea" flow on phones: the DS bottom sheet that
 * hosts IdeaSubmitForm, opened from the bottom tab bar. Desktop keeps the
 * submit page. Loads the form fresh on every open (the time-trap stamp is
 * single-use per form load); a guest-less anonymous visitor is sent to the
 * submit page, which owns the login redirect.
 */

import { ErrorState, LoadingState, Sheet } from '@votepit/ui/ds'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import type { BoardPhasesInfo, Tag, TagSuggestionAvailability, User } from '../lib/api'
import { getSubmitForm } from '../lib/api'
import { useT } from '../lib/i18n/context'
import { IdeaSubmitForm } from './IdeaSubmitForm'

type SheetForm =
  | { tag: 'loading' }
  | { tag: 'error' }
  | {
      tag: 'ready'
      formAt: string
      phases: BoardPhasesInfo | undefined
      tags: Tag[]
      tagSuggestion?: TagSuggestionAvailability
      guestSiteKey: string | null
    }

export interface NewIdeaSheetProps {
  open: boolean
  onClose: () => void
  boardSlug: string
  boardHref: string
  user: User | null
}

export function NewIdeaSheet({ open, onClose, boardSlug, boardHref, user }: NewIdeaSheetProps) {
  const t = useT('submitPage')
  const tCommon = useT('common')
  const navigate = useNavigate()
  const [form, setForm] = useState<SheetForm>({ tag: 'loading' })
  // Keeps the effect below keyed on open/board only, not on handler identity.
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!open) {
      setForm({ tag: 'loading' })
      return
    }
    let cancelled = false
    setForm({ tag: 'loading' })
    getSubmitForm(boardSlug)
      .then((data) => {
        if (cancelled) return
        const guestSiteKey =
          !data.is_authenticated &&
          data.board.anonymous_submission_available === true &&
          typeof data.board.turnstile_site_key === 'string' &&
          data.board.turnstile_site_key !== ''
            ? data.board.turnstile_site_key
            : null
        if (!data.is_authenticated && guestSiteKey === null) {
          onCloseRef.current()
          navigate(accountPath(`/${boardSlug}/submit`))
          return
        }
        setForm({
          tag: 'ready',
          formAt: data.form_at,
          phases: data.board.phases,
          tags: data.board.tags ?? [],
          tagSuggestion: data.board.tag_suggestion?.availability,
          guestSiteKey,
        })
      })
      .catch(() => {
        if (!cancelled) setForm({ tag: 'error' })
      })
    return () => {
      cancelled = true
    }
  }, [open, boardSlug, navigate])

  return (
    <Sheet
      open={open}
      onClose={onClose}
      title={t('heading')}
      closeLabel={tCommon('action.close')}
      testId="new-idea-sheet"
    >
      {form.tag === 'loading' && <LoadingState label={t('loading')} rows={3} />}
      {form.tag === 'error' && (
        <ErrorState title={tCommon('state.errorTitle')} description={tCommon('state.error')} />
      )}
      {form.tag === 'ready' && (
        <IdeaSubmitForm
          key={form.formAt}
          boardSlug={boardSlug}
          boardHref={boardHref}
          phases={form.phases}
          tags={form.tags}
          tagSuggestion={form.tagSuggestion}
          guestSiteKey={form.guestSiteKey}
          user={user}
          formAt={form.formAt}
          onCancel={onClose}
          onCreated={(ideaId) => {
            onClose()
            navigate(accountPath(`/${boardSlug}/idea/${ideaId}`))
          }}
        />
      )}
    </Sheet>
  )
}

/**
 * WorklistRow — one row of the operator worklist with its row actions (P-06
 * slice 04 and part B).
 *
 * Every action calls an existing endpoint (the only new write is the hide
 * action, a dismissal): answer (link to the composer), "Entscheiden"
 * (WorklistDecisionDialog: IdeaStatusAction with a mandatory explanation and
 * the closed-for-votes statuses), merge (MergeIdeaDialog), approve, reject
 * (ContentModerationModal) and approve-and-trust (pending member ideas only),
 * plus "Nur ausblenden" (hide only: no notification, voting stays open). On a
 * frozen board the decision and the hide action are not offered (owner rule
 * 11b; the server answers 423 anyway). A successful action reports the row as
 * gone; the page removes it, reloads the counts and notifies the admin shell's
 * badge. A failed action keeps the row and shows the server's message in it.
 */

import { Badge, Button, ButtonLink, InlineNotice } from '@votepit/ui/ds'
import type { AnchorHTMLAttributes } from 'react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import type { ApiError, ModerationReasonCode, WorklistIdea } from '../lib/api'
import {
  approveAndTrustAuthor,
  approveModerationQueue,
  dismissWorklistIdea,
  moderateIdea,
} from '../lib/api'
import { labelOfStatus, useStatusDefaultLabels } from '../lib/boardStatuses'
import { useI18n, useT } from '../lib/i18n/context'
import { ContentModerationModal } from './ContentModerationModal'
import { MergeIdeaDialog } from './MergeIdeaDialog'
import { WorklistDecisionDialog } from './WorklistDecisionDialog'

/** Router link adapter for ButtonLink (client-side navigation). */
export function RouterAnchor({
  href,
  ...props
}: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) {
  return <Link to={href} {...props} />
}

/** How a row left the list: an action that resolved it, or a dismissal (undoable). */
export type WorklistRowExit = 'done' | 'dismissed'

interface WorklistRowProps {
  idea: WorklistIdea
  /** Called once the row's action succeeded; the page removes the row. */
  onGone: (ideaId: number, exit: WorklistRowExit) => void
}

/** The error text of a failed request: the server's message when it has one. */
function messageOf(err: unknown, fallback: string): string {
  const apiErr = err as ApiError
  const message = apiErr?.payload?.message
  if (typeof message === 'string' && message !== '') return message
  return fallback
}

export function WorklistRow({ idea, onGone }: WorklistRowProps) {
  const t = useT('worklistPage')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [decideOpen, setDecideOpen] = useState(false)
  const [mergeOpen, setMergeOpen] = useState(false)
  const [rejectOpen, setRejectOpen] = useState(false)

  const ideaId = idea.id
  const boardSlug = idea.board_slug
  const ideaHref = accountPath(`/${boardSlug}/idea/${ideaId}`)
  const { categories } = idea
  const pending = idea.moderation_state === 'pending'
  const frozen = idea.board_frozen
  const { language } = useI18n()
  const statusDefaults = useStatusDefaultLabels()
  const statusName = labelOfStatus(
    {
      key: idea.status,
      category: idea.status_category,
      is_system: idea.status_is_system,
      label_de: idea.status_label_de,
      label_en: idea.status_label_en,
    },
    language,
    statusDefaults,
  )
  const aria = (action: string) => t('actionAria', { action, title: idea.title })

  /** Runs one action: busy lock, error text in the row, `onGone` on success. */
  const run = async (exit: WorklistRowExit, action: () => Promise<void>, fallback: string) => {
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      await action()
      onGone(ideaId, exit)
    } catch (err) {
      setError(messageOf(err, fallback))
      setBusy(false)
    }
  }

  const approve = () =>
    run(
      'done',
      async () => {
        const res = await approveModerationQueue(boardSlug, [ideaId])
        if (!res.approved.includes(ideaId)) throw new Error(t('alreadyHandled'))
      },
      t('actionFailed'),
    )

  const trust = () =>
    run(
      'done',
      async () => {
        const res = await approveAndTrustAuthor(boardSlug, ideaId)
        if (!res.approved.includes(ideaId)) throw new Error(t('alreadyHandled'))
      },
      t('actionFailed'),
    )

  const dismiss = () =>
    run(
      'dismissed',
      async () => {
        await dismissWorklistIdea(ideaId)
      },
      t('actionFailed'),
    )

  return (
    <li data-testid={`worklist-row-${ideaId}`} className="flex min-w-0 flex-col gap-2">
      <Link
        to={ideaHref}
        data-testid={`worklist-row-${ideaId}-link`}
        className="min-w-0 break-words font-medium text-vp-text underline-offset-4 hover:underline"
      >
        {idea.title}
      </Link>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-vp-sm text-vp-text-muted">
        <span className="break-words">{idea.board_name}</span>
        <span>{statusName}</span>
        <span>{t('voteSummary', { up: idea.up_count, down: idea.down_count })}</span>
        <span>{t('rowAge', { count: idea.age_days })}</span>
        {idea.author_type !== null && (
          <span>{idea.author_type === 'guest' ? t('authorGuest') : t('authorMember')}</span>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        {categories.unanswered && <Badge tone="info">{t('reasonUnanswered')}</Badge>}
        {categories.pending_moderation && (
          <Badge tone="warning">{t('reasonPendingModeration')}</Badge>
        )}
        {categories.decision_due && <Badge tone="accent">{t('reasonDecisionDue')}</Badge>}
      </div>

      {!pending && (
        <>
          {frozen && (
            <p
              data-testid={`worklist-row-${ideaId}-frozen`}
              className="text-vp-sm text-vp-text-muted"
            >
              {t('frozenNote')}
            </p>
          )}
          <div
            role="group"
            aria-label={t('actionsLabel', { title: idea.title })}
            className="flex flex-wrap gap-2"
          >
            <ButtonLink
              href={`${ideaHref}#comment-body`}
              linkAs={RouterAnchor}
              variant="secondary"
              testId={`worklist-row-${ideaId}-answer`}
              aria-label={aria(t('actionAnswer'))}
            >
              {t('actionAnswer')}
            </ButtonLink>
            {!frozen && (
              <Button
                variant="primary"
                testId={`worklist-row-${ideaId}-decide`}
                aria-label={aria(t('actionDecide'))}
                disabled={busy}
                onClick={() => setDecideOpen(true)}
              >
                {t('actionDecide')}
              </Button>
            )}
            <Button
              variant="secondary"
              testId={`worklist-row-${ideaId}-merge`}
              aria-label={aria(t('actionMerge'))}
              disabled={busy}
              onClick={() => setMergeOpen(true)}
            >
              {t('actionMerge')}
            </Button>
            {!frozen && (
              <Button
                variant="text"
                testId={`worklist-row-${ideaId}-dismiss`}
                aria-label={aria(t('actionDismiss'))}
                title={t('actionDismissHint')}
                loading={busy}
                onClick={() => void dismiss()}
              >
                {t('actionDismiss')}
              </Button>
            )}
          </div>
        </>
      )}

      {pending && (
        <div
          role="group"
          aria-label={t('actionsLabel', { title: idea.title })}
          className="flex flex-wrap gap-2"
        >
          <Button
            variant="primary"
            testId={`worklist-row-${ideaId}-approve`}
            aria-label={aria(t('actionApprove'))}
            loading={busy}
            onClick={() => void approve()}
          >
            {t('actionApprove')}
          </Button>
          <Button
            variant="destructive"
            testId={`worklist-row-${ideaId}-reject`}
            aria-label={aria(t('actionReject'))}
            disabled={busy}
            onClick={() => setRejectOpen(true)}
          >
            {t('actionReject')}
          </Button>
          {idea.author_type === 'member' && (
            <Button
              variant="secondary"
              testId={`worklist-row-${ideaId}-trust`}
              aria-label={aria(t('actionTrust'))}
              disabled={busy}
              onClick={() => void trust()}
            >
              {t('actionTrust')}
            </Button>
          )}
        </div>
      )}

      {error !== null && (
        <InlineNotice tone="error" testId={`worklist-row-${ideaId}-error`}>
          {error}
        </InlineNotice>
      )}

      {decideOpen && (
        <WorklistDecisionDialog
          boardSlug={boardSlug}
          ideaId={ideaId}
          currentStatus={idea.status}
          onClose={() => setDecideOpen(false)}
          onDecided={() => {
            setDecideOpen(false)
            onGone(ideaId, 'done')
          }}
        />
      )}

      {mergeOpen && (
        <MergeIdeaDialog
          open
          boardSlug={boardSlug}
          ideaId={ideaId}
          onClose={() => setMergeOpen(false)}
          onMerged={() => {
            setMergeOpen(false)
            onGone(ideaId, 'done')
          }}
        />
      )}

      {rejectOpen && (
        <ContentModerationModal
          open
          entityType="idea"
          onClose={() => setRejectOpen(false)}
          onConfirm={async (reasonCode: ModerationReasonCode, reasonNote: string) => {
            // The modal shows a thrown error inline and stays open (same as the idea page).
            await moderateIdea(
              boardSlug,
              ideaId,
              reasonCode,
              reasonNote === '' ? undefined : reasonNote,
            )
            setRejectOpen(false)
            onGone(ideaId, 'done')
          }}
        />
      )}
    </li>
  )
}

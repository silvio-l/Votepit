/**
 * WorklistDecisionDialog — "Entscheiden" of one worklist row (P-06 part B,
 * owner decision Q12b).
 *
 * Sets a status through the existing status endpoint (IdeaStatusAction ->
 * IdeaStatusChanger), the same change the idea page's moderator select makes.
 * The offered statuses are the board's own registry, in the board's order,
 * filtered by the server's `allowed_next` (fail-closed when absent), without
 * the current and the hidden statuses. A status with allow_votes = false
 * closes voting; picking one says so. When the board requires a reason
 * (`status_reason_required`, read from the idea payload), save stays disabled
 * while it is blank; otherwise the reason stays optional.
 */

import { InlineNotice, Select, Sheet } from '@votepit/ui/ds'
import { useEffect, useState } from 'react'
import type { ApiError, BoardStatusInfo } from '../lib/api'
import { getIdea, setIdeaStatus } from '../lib/api'
import type { StatusDecisionInput } from '../lib/api/ideas'
import { labelOfStatus, useStatusDefaultLabels } from '../lib/boardStatuses'
import { useI18n, useT } from '../lib/i18n/context'
import { StatusDecisionForm } from './StatusDecisionForm'

interface WorklistDecisionDialogProps {
  boardSlug: string
  ideaId: number
  /** Status key the idea has now; it is never offered as the target. */
  currentStatus: string
  onClose: () => void
  /** Called once the status change was saved; the row leaves the worklist. */
  onDecided: () => void
}

export function WorklistDecisionDialog({
  boardSlug,
  ideaId,
  currentStatus,
  onClose,
  onDecided,
}: WorklistDecisionDialogProps) {
  const t = useT('worklistPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const statusDefaults = useStatusDefaultLabels()
  const [targets, setTargets] = useState<BoardStatusInfo[] | null>(null)
  // Board setting (owner/admin): a reason is mandatory only when the board says so.
  const [reasonRequired, setReasonRequired] = useState(false)
  const [loadFailed, setLoadFailed] = useState(false)
  const [target, setTarget] = useState('')
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    getIdea(boardSlug, ideaId)
      .then((res) => {
        if (cancelled) return
        // Fail-closed: without the server's allowed_next nothing is offered.
        const allowedNext = new Set(res.idea.allowed_next ?? [])
        setReasonRequired(res.board.status_reason_required === true)
        setTargets(
          (res.board.statuses ?? []).filter(
            (s) => allowedNext.has(s.key) && !s.is_hidden && s.key !== currentStatus,
          ),
        )
      })
      .catch(() => {
        if (!cancelled) setLoadFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug, ideaId, currentStatus])

  const optionLabel = (s: BoardStatusInfo): string => labelOfStatus(s, language, statusDefaults)

  const targetInfo = (targets ?? []).find((s) => s.key === target)

  const submit = async (decision: StatusDecisionInput) => {
    if (pending || targetInfo === undefined) return
    setPending(true)
    setError(null)
    try {
      await setIdeaStatus(boardSlug, ideaId, targetInfo.key, decision)
      onDecided()
    } catch (err) {
      const message = (err as ApiError)?.payload?.message
      setError(typeof message === 'string' && message !== '' ? message : t('actionFailed'))
      setPending(false)
    }
  }

  const close = () => {
    if (!pending) onClose()
  }

  return (
    <Sheet
      open
      onClose={close}
      title={t('decideTitle')}
      closeLabel={tCommon('action.close')}
      testId="worklist-decide-dialog"
    >
      <div className="flex flex-col gap-3">
        <p className="text-vp-sm text-vp-text-secondary text-pretty">
          {reasonRequired ? t('decideDescription') : t('decideDescriptionOptional')}
        </p>

        {loadFailed && (
          <InlineNotice tone="error" testId="worklist-decide-load-error">
            {t('statusLoadError')}
          </InlineNotice>
        )}
        {!loadFailed && targets === null && (
          <p className="text-vp-sm text-vp-text-muted" aria-live="polite">
            {t('loading')}
          </p>
        )}
        {targets !== null && targets.length === 0 && (
          <InlineNotice tone="info" testId="worklist-decide-no-targets">
            {t('decideNoTargets')}
          </InlineNotice>
        )}

        {targets !== null && targets.length > 0 && (
          <>
            <Select
              label={t('statusPick')}
              testId="worklist-decide-status"
              value={target}
              onChange={setTarget}
              disabled={pending}
            >
              <option value="">{t('statusPickPlaceholder')}</option>
              {targets.map((s) => (
                <option key={s.key} value={s.key}>
                  {optionLabel(s)}
                </option>
              ))}
            </Select>
            {targetInfo?.allow_votes === false && (
              <p
                data-testid="worklist-decide-closes-voting"
                className="text-vp-sm text-vp-text-muted"
              >
                {t('decideClosesVoting')}
              </p>
            )}
            {targetInfo !== undefined && (
              <StatusDecisionForm
                testIdPrefix="worklist-decide"
                statusLabel={optionLabel(targetInfo)}
                declined={targetInfo.category === 'declined'}
                reasonRequired={reasonRequired}
                pending={pending}
                onSubmit={(decision) => void submit(decision)}
                onCancel={() => setTarget('')}
              />
            )}
          </>
        )}

        {error !== null && (
          <InlineNotice tone="error" testId="worklist-decide-error">
            {error}
          </InlineNotice>
        )}
      </div>
    </Sheet>
  )
}

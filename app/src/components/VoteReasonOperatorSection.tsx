import { Button, ConfirmDialog, VoteReasonAggregateView } from '@votepit/ui/ds'
import { useEffect, useId, useMemo, useState } from 'react'
import { fetchVoteReasons, removeVoteReasonNote, type VoteReasonAggregate } from '../lib/api/ideas'
import { useT } from '../lib/i18n/context'
import { buildVoteReasonAggregateLabels } from '../lib/voteReason'

/**
 * Operator-only section (P-03): counts per reason and free texts without names
 * or times. Loaded on first open; the API refuses non-moderators with 403.
 * Moderators can take down single free texts (owner answer 22a); the reason
 * choice stays and the count does not change.
 */
export function VoteReasonOperatorSection({
  testId,
  boardSlug,
  ideaId,
}: {
  testId: string
  boardSlug: string
  ideaId: number
}) {
  const t = useT('voteReasons')
  const labels = useMemo(() => buildVoteReasonAggregateLabels(t), [t])
  const [open, setOpen] = useState(false)
  const [data, setData] = useState<VoteReasonAggregate | null>(null)
  const [failed, setFailed] = useState(false)
  const [pendingIndex, setPendingIndex] = useState<number | null>(null)
  const [removing, setRemoving] = useState(false)
  const [removeFailed, setRemoveFailed] = useState(false)
  const bodyId = useId()

  useEffect(() => {
    if (!open || data !== null || failed) return
    let cancelled = false
    fetchVoteReasons(boardSlug, ideaId)
      .then((result) => {
        if (!cancelled) setData(result)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [open, data, failed, boardSlug, ideaId])

  const pendingNote =
    pendingIndex !== null && data !== null ? (data.notes[pendingIndex] ?? null) : null

  async function confirmRemoval() {
    if (pendingIndex === null || pendingNote === null || data === null) return
    setRemoving(true)
    setRemoveFailed(false)
    try {
      await removeVoteReasonNote(boardSlug, ideaId, pendingNote.reason, pendingNote.note)
      const index = pendingIndex
      setData({ ...data, notes: data.notes.filter((_, i) => i !== index) })
      setPendingIndex(null)
    } catch {
      setRemoveFailed(true)
    } finally {
      setRemoving(false)
    }
  }

  return (
    <section data-testid={testId} className="mt-4">
      <h3 className="m-0">
        <button
          type="button"
          className="ds-vote-reason-aggregate__toggle"
          aria-expanded={open}
          aria-controls={bodyId}
          onClick={() => setOpen((value) => !value)}
          data-testid={`${testId}-toggle`}
        >
          {t('operatorTitle')}
        </button>
      </h3>
      {open && (
        <div id={bodyId} className="mt-2" aria-busy={data === null && !failed}>
          {failed ? (
            <p role="alert">{t('operatorLoadFailed')}</p>
          ) : data !== null ? (
            <>
              {removeFailed && (
                <p role="alert" data-testid={`${testId}-remove-error`}>
                  {t('operatorRemoveFailed')}
                </p>
              )}
              <VoteReasonAggregateView
                testId={`${testId}-view`}
                labels={{ ...labels, title: '' }}
                aggregate={data}
                renderNoteAction={(_item, index) => (
                  <Button
                    variant="text"
                    testId={`${testId}-note-remove-${index}`}
                    onClick={() => {
                      setRemoveFailed(false)
                      setPendingIndex(index)
                    }}
                  >
                    {t('operatorRemoveNote')}
                  </Button>
                )}
              />
              <ConfirmDialog
                testId={`${testId}-note-confirm`}
                open={pendingNote !== null}
                title={t('operatorRemoveTitle')}
                description={t('operatorRemoveBody')}
                confirmLabel={t('operatorRemoveConfirm')}
                cancelLabel={t('operatorRemoveCancel')}
                tone="danger"
                busy={removing}
                onConfirm={() => void confirmRemoval()}
                onCancel={() => setPendingIndex(null)}
              />
            </>
          ) : null}
        </div>
      )}
    </section>
  )
}

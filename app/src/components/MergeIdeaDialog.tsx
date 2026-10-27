import { Badge, Button, InlineNotice, SearchField } from '@votepit/ui/ds'
import type { FormEvent } from 'react'
import { useEffect, useId, useRef, useState } from 'react'
import type { ApiError, DuplicateConfirmation, MergeCandidate } from '../lib/api'
import { getMergeCandidates, mergeIdea } from '../lib/api'
import { useT } from '../lib/i18n/context'
import { PlanUpgradeLink } from './PlanUpgradeLink'

/** Same focus-trap selector as ContentModerationModal (shared dialog shape). */
const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

/** Debounce for the title search while typing. */
const SEARCH_DEBOUNCE_MS = 250

interface MergeIdeaDialogProps {
  open: boolean
  boardSlug: string
  ideaId: number
  onClose: () => void
  /** Called with the target idea's id once the merge succeeded. */
  onMerged: (targetId: number) => void
}

/**
 * "Merge into…" picker for moderators (issue 10). Without a query it lists
 * the server's deterministic similar-title suggestions
 * (DuplicateCandidateFinder); typing switches to a title search. The server
 * enforces every rule (same board, no chains, not into itself) — this
 * dialog only surfaces its answer. Where a server extension confirms the
 * suggestions (issue 11), confirmed ones carry an "AI match" badge; when the
 * account's plan lacks it, a short plan hint is shown instead.
 */
export function MergeIdeaDialog({
  open,
  boardSlug,
  ideaId,
  onClose,
  onMerged,
}: MergeIdeaDialogProps) {
  const t = useT('ideaDetailPage')
  const tCommon = useT('common')
  const titleId = useId()
  const panelRef = useRef<HTMLDivElement>(null)
  const restoreFocusRef = useRef<HTMLElement | null>(null)
  const [query, setQuery] = useState('')
  const [candidates, setCandidates] = useState<MergeCandidate[]>([])
  const [confirmation, setConfirmation] = useState<DuplicateConfirmation | null>(null)
  const [loading, setLoading] = useState(false)
  const [selected, setSelected] = useState<number | null>(null)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setQuery('')
      setSelected(null)
      setError(null)
      setPending(false)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    let cancelled = false
    const timer = window.setTimeout(
      () => {
        setLoading(true)
        getMergeCandidates(boardSlug, ideaId, query)
          .then((res) => {
            if (cancelled) return
            setCandidates(res.candidates)
            setConfirmation(res.confirmation ?? null)
          })
          .catch(() => {
            if (cancelled) return
            setCandidates([])
            setConfirmation(null)
          })
          .finally(() => {
            if (!cancelled) setLoading(false)
          })
      },
      query.trim() === '' ? 0 : SEARCH_DEBOUNCE_MS,
    )
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [open, boardSlug, ideaId, query])

  useEffect(() => {
    if (!open) return
    restoreFocusRef.current = document.activeElement as HTMLElement | null
    panelRef.current?.querySelector<HTMLElement>(FOCUSABLE_SELECTOR)?.focus()
    return () => {
      restoreFocusRef.current?.focus()
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !pending) {
        onClose()
        return
      }
      if (e.key !== 'Tab') return
      const focusables = panelRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)
      if (!focusables || focusables.length === 0) return
      const first = focusables[0]
      const last = focusables[focusables.length - 1]
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first.focus()
      }
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [open, pending, onClose])

  if (!open) return null

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (pending || selected === null) return
    setPending(true)
    setError(null)
    try {
      await mergeIdea(boardSlug, ideaId, selected)
      onMerged(selected)
    } catch (err) {
      const key = (err as ApiError)?.payload?.key
      setError(key === 'merge_has_merged_children' ? t('mergeHasChildren') : t('mergeFailed'))
      setPending(false)
    }
  }

  const searching = query.trim() !== ''

  return (
    <div className="fixed inset-0 z-[100] flex items-end sm:items-center justify-center p-4 animate-vp-fade-in">
      <button
        type="button"
        aria-hidden="true"
        tabIndex={-1}
        onClick={() => !pending && onClose()}
        className="absolute inset-0 bg-vp-ink/60 cursor-default"
      />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative w-full max-w-md vp-overlay overflow-hidden animate-vp-scale-in"
      >
        <div className="px-5 pt-5 pb-4">
          <h2
            id={titleId}
            className="font-archivo font-bold text-vp-lg text-vp-ink tracking-[-0.02em] leading-6"
          >
            {t('mergeDialogTitle')}
          </h2>
          <p className="mt-1.5 text-vp-sm text-vp-text-secondary text-pretty">
            {t('mergeDialogDescription')}
          </p>
        </div>
        <form onSubmit={(e) => void handleSubmit(e)} className="px-5 pb-5 flex flex-col gap-3">
          <SearchField
            testId="merge-idea-search"
            label={t('mergeSearchLabel')}
            placeholder={t('mergeSearchPlaceholder')}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            disabled={pending}
            name="merge-search"
          />
          <fieldset className="min-w-0">
            <legend className="vp-eyebrow mb-1.5">
              {searching ? t('mergeResultsHeading') : t('mergeSuggestionsHeading')}
            </legend>
            {loading && candidates.length === 0 ? (
              <p className="text-vp-sm text-vp-text-muted" aria-live="polite">
                {t('mergeLoading')}
              </p>
            ) : candidates.length === 0 ? (
              <p className="text-vp-sm text-vp-text-muted">{t('mergeNoCandidates')}</p>
            ) : (
              <ul className="flex flex-col gap-1 max-h-64 overflow-y-auto" aria-busy={loading}>
                {candidates.map((c) => (
                  <li key={c.id}>
                    <label className="flex items-start gap-2.5 rounded-md px-2 py-1.5 cursor-pointer hover:bg-vp-surface-frost">
                      <input
                        type="radio"
                        name="merge-target"
                        value={c.id}
                        checked={selected === c.id}
                        onChange={() => setSelected(c.id)}
                        disabled={pending}
                        className="mt-1"
                      />
                      <span className="min-w-0">
                        <span className="block text-vp-sm text-vp-ink break-words">
                          {c.title}
                          {c.confirmed === true && (
                            <Badge tone="accent" className="ml-1.5 align-middle">
                              {t('mergeAiMatch')}
                            </Badge>
                          )}
                        </span>
                        <span className="block text-vp-xs text-vp-text-muted">
                          {t('mergeVotes', { up: String(c.up_count), down: String(c.down_count) })}
                        </span>
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
            )}
          </fieldset>
          {!searching && confirmation === 'upgrade_required' && (
            <p className="text-vp-xs text-vp-text-muted">
              {t('mergeAiUpgradeHint')} <PlanUpgradeLink label={t('mergeUpgradeLinkLabel')} />
            </p>
          )}
          {error !== null && <InlineNotice tone="error">{error}</InlineNotice>}
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-1">
            <Button
              type="button"
              variant="secondary"
              testId="merge-idea-cancel"
              onClick={onClose}
              disabled={pending}
            >
              {tCommon('action.cancel')}
            </Button>
            <Button
              type="submit"
              testId="merge-idea-confirm"
              disabled={pending || selected === null}
              loading={pending}
            >
              {pending ? t('mergeSubmitting') : t('mergeConfirm')}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}

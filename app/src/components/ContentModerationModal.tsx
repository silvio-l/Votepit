import { Button, InlineNotice, Select, TextArea } from '@votepit/ui/ds'
import type { FormEvent } from 'react'
import { useEffect, useId, useRef, useState } from 'react'
import type { ModerationReasonCode } from '../lib/api'
import { useT } from '../lib/i18n/context'

/** Matches ReportContentModal's focus-trap intent (this modal shares its
 * dialog shape/behavior — the public DSA report form and the moderator
 * removal form are the two "give a structured reason" surfaces in this
 * app). */
const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

const ILLEGAL_SUBCATEGORY_LABEL_KEYS = {
  illegal_copyright: 'commentModeration.illegalCopyright',
  illegal_privacy: 'commentModeration.illegalPrivacy',
  illegal_threat: 'commentModeration.illegalThreat',
  illegal_fraud: 'commentModeration.illegalFraud',
  illegal_other: 'commentModeration.illegalOther',
} as const satisfies Record<string, string>

const ILLEGAL_SUBCATEGORIES = Object.keys(
  ILLEGAL_SUBCATEGORY_LABEL_KEYS,
) as (keyof typeof ILLEGAL_SUBCATEGORY_LABEL_KEYS)[]

const MAX_NOTE_LENGTH = 2000

/** Entity-specific i18n keys (title/description/error) — everything else
 * (reason select, note field, buttons) is generic enough to share the
 * `commentModeration.*` namespace across both entity types. */
const ENTITY_LABEL_KEYS = {
  comment: {
    title: 'commentModeration.title',
    description: 'commentModeration.description',
    errorGeneric: 'commentModeration.errorGeneric',
  },
  idea: {
    title: 'ideaModeration.title',
    description: 'ideaModeration.description',
    errorGeneric: 'ideaModeration.errorGeneric',
  },
} as const satisfies Record<string, Record<'title' | 'description' | 'errorGeneric', string>>

interface ContentModerationModalProps {
  open: boolean
  /** Which content type this removal targets — board-operator-compliance
   * issue 07 generalized the comment-only modal (issue 06) into this
   * shared component rather than duplicating it for ideas. */
  entityType: 'comment' | 'idea'
  onClose: () => void
  onConfirm: (reasonCode: ModerationReasonCode, reasonNote: string) => Promise<void>
}

/**
 * Reason-code removal form (board-operator-compliance issue 06, generalized
 * to ideas in issue 07) — replaces the previous plain "really remove this?"
 * ConfirmDialog. Category is either 'community_rule', a specific
 * 'illegal_*' subcategory (picked via a second, conditional select), or
 * 'other' — the same 7-value reason_code enum CommentModerationAction/
 * IdeaModerationAction validate server-side.
 */
export function ContentModerationModal({
  open,
  entityType,
  onClose,
  onConfirm,
}: ContentModerationModalProps) {
  const t = useT('common')
  const titleId = useId()
  const panelRef = useRef<HTMLDivElement>(null)
  const restoreFocusRef = useRef<HTMLElement | null>(null)
  const [category, setCategory] = useState<'community_rule' | 'illegal' | 'other'>('community_rule')
  const [illegalSubcategory, setIllegalSubcategory] =
    useState<ModerationReasonCode>('illegal_copyright')
  const [note, setNote] = useState('')
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const labels = ENTITY_LABEL_KEYS[entityType]

  useEffect(() => {
    if (open) {
      setCategory('community_rule')
      setIllegalSubcategory('illegal_copyright')
      setNote('')
      setError(null)
      setPending(false)
    }
  }, [open])

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

  const reasonCode: ModerationReasonCode =
    category === 'illegal'
      ? illegalSubcategory
      : category === 'community_rule'
        ? 'community_rule'
        : 'other'

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (pending) return
    setPending(true)
    setError(null)
    try {
      await onConfirm(reasonCode, note.trim())
    } catch (err) {
      setError(err instanceof Error && err.message !== '' ? err.message : t(labels.errorGeneric))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-end sm:items-center justify-center p-4 animate-vp-fade-in">
      <button
        type="button"
        data-testid="content-moderation-backdrop"
        aria-hidden="true"
        tabIndex={-1}
        onClick={() => !pending && onClose()}
        className="absolute inset-0 bg-vp-ink/60 cursor-default"
      />
      <div
        ref={panelRef}
        role="dialog"
        data-testid="content-moderation-modal"
        aria-modal="true"
        aria-labelledby={titleId}
        className="w-full max-w-md vp-overlay overflow-hidden animate-vp-scale-in"
      >
        <div className="px-5 pt-5 pb-4">
          <h2
            id={titleId}
            className="font-archivo font-bold text-vp-lg text-vp-ink tracking-[-0.02em] leading-6"
          >
            {t(labels.title)}
          </h2>
          <p className="mt-1.5 text-vp-sm text-vp-text-secondary text-pretty">
            {t(labels.description)}
          </p>
        </div>
        <form onSubmit={(e) => void handleSubmit(e)} className="px-5 pb-5 flex flex-col gap-3">
          <Select
            testId="content-moderation-category"
            id="content-moderation-category"
            label={t('commentModeration.reasonLabel')}
            value={category}
            onChange={(value) => setCategory(value as 'community_rule' | 'illegal' | 'other')}
            disabled={pending}
          >
            <option value="community_rule">{t('commentModeration.reasonCommunityRule')}</option>
            <option value="illegal">{t('commentModeration.reasonIllegal')}</option>
            <option value="other">{t('commentModeration.reasonOther')}</option>
          </Select>
          {category === 'illegal' && (
            <Select
              testId="content-moderation-illegal-subcategory"
              id="content-moderation-illegal-subcategory"
              label={t('commentModeration.illegalCategoryLabel')}
              value={illegalSubcategory}
              onChange={(value) => setIllegalSubcategory(value as ModerationReasonCode)}
              disabled={pending}
            >
              {ILLEGAL_SUBCATEGORIES.map((code) => (
                <option key={code} value={code}>
                  {t(ILLEGAL_SUBCATEGORY_LABEL_KEYS[code])}
                </option>
              ))}
            </Select>
          )}
          <TextArea
            testId="content-moderation-note"
            id="content-moderation-note"
            label={t('commentModeration.noteLabel')}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder={t('commentModeration.notePlaceholder')}
            rows={3}
            maxLength={MAX_NOTE_LENGTH}
            disabled={pending}
          />
          {error !== null && (
            <InlineNotice tone="error" testId="content-moderation-error">
              {error}
            </InlineNotice>
          )}
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-1">
            <Button
              testId="content-moderation-cancel"
              type="button"
              variant="secondary"
              onClick={onClose}
              disabled={pending}
            >
              {t('commentModeration.cancel')}
            </Button>
            <Button
              testId="content-moderation-submit"
              type="submit"
              variant="destructive"
              disabled={pending}
              loading={pending}
            >
              {pending ? t('commentModeration.submitting') : t('commentModeration.submit')}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}

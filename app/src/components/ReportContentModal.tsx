import { Alert, Button, Textarea, TextInput } from '@votepit/ui'
import type { FormEvent } from 'react'
import { useEffect, useId, useRef, useState } from 'react'
import type { ApiError } from '../lib/api'
import { submitReport } from '../lib/api'
import { useT } from '../lib/i18n/context'

/** Matches ConfirmDialog's focus-trap intent, generalized: this modal's
 * focusable set varies (form fields vs. the "done" state's single button),
 * unlike ConfirmDialog's fixed two buttons. */
const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

interface ReportContentModalProps {
  open: boolean
  onClose: () => void
  /** The page URL the report refers to (DSA Art. 16 requires identifying the content). */
  url: string
  accountSlug?: string
  boardSlug?: string
  ideaId?: number
}

const MIN_REASON_LENGTH = 10

/**
 * The public DSA Art. 16 notice-and-action entry point: any visitor, signed
 * in or not, can flag content from wherever it appears. Submits straight to
 * the existing POST /reports endpoint (AbuseReportAction) — this modal is
 * the missing UI on top of an otherwise-complete backend/operator-review
 * flow, not a new feature.
 */
export function ReportContentModal({
  open,
  onClose,
  url,
  accountSlug,
  boardSlug,
  ideaId,
}: ReportContentModalProps) {
  const t = useT('common')
  const titleId = useId()
  const panelRef = useRef<HTMLDivElement>(null)
  const restoreFocusRef = useRef<HTMLElement | null>(null)
  const [reason, setReason] = useState('')
  const [email, setEmail] = useState('')
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState(false)

  useEffect(() => {
    if (open) {
      setReason('')
      setEmail('')
      setError(null)
      setDone(false)
      setPending(false)
    }
  }, [open])

  // Focus trap + restore, same intent as ConfirmDialog: move focus into the
  // panel on open, return it to whatever opened the modal on close. Unlike
  // ConfirmDialog's fixed two buttons, this panel's focusable set varies
  // (form fields vs. the "done" state's single button), so the first
  // focusable element is found by query rather than a hardcoded ref.
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
    const trimmed = reason.trim()
    if (pending || trimmed.length < MIN_REASON_LENGTH) return
    setPending(true)
    setError(null)
    try {
      await submitReport({
        url,
        reason: trimmed,
        account_slug: accountSlug,
        board_slug: boardSlug,
        idea_id: ideaId,
        reporter_email: email.trim() === '' ? undefined : email.trim(),
      })
      setDone(true)
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('report.errorGeneric'))
    } finally {
      setPending(false)
    }
  }

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
        className="w-full max-w-md vp-overlay overflow-hidden animate-vp-scale-in"
      >
        <div className="px-5 pt-5 pb-4">
          <h2
            id={titleId}
            className="font-archivo font-bold text-vp-lg text-vp-ink tracking-[-0.02em] leading-6"
          >
            {t('report.title')}
          </h2>
          <p className="mt-1.5 text-vp-sm text-vp-text-secondary text-pretty">
            {t('report.description')}
          </p>
        </div>
        {done ? (
          <div className="px-5 pb-5">
            <Alert tone="success">{t('report.success')}</Alert>
            <div className="mt-4 flex justify-end">
              <Button type="button" variant="secondary" onClick={onClose}>
                {t('report.cancel')}
              </Button>
            </div>
          </div>
        ) : (
          <form onSubmit={(e) => void handleSubmit(e)} className="px-5 pb-5 flex flex-col gap-3">
            <Textarea
              id="report-reason"
              label={t('report.reasonLabel')}
              value={reason}
              onChange={setReason}
              placeholder={t('report.reasonPlaceholder')}
              rows={4}
              maxLength={2000}
              disabled={pending}
            />
            <TextInput
              id="report-email"
              type="email"
              label={t('report.emailLabel')}
              hint={t('report.emailHint')}
              value={email}
              onChange={setEmail}
              disabled={pending}
            />
            {error !== null && <Alert tone="error">{error}</Alert>}
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-1">
              <Button type="button" variant="secondary" onClick={onClose} disabled={pending}>
                {t('report.cancel')}
              </Button>
              <Button
                type="submit"
                variant="danger"
                disabled={pending || reason.trim().length < MIN_REASON_LENGTH}
                loading={pending}
              >
                {pending ? t('report.submitting') : t('report.submit')}
              </Button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}

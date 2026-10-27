/**
 * PersonalDataSection — ProfilePage's GDPR self-service for every user
 * (competitor-parity issue 12), on every plan and in self-host:
 *   - "Download my data" (GET /account/export, JSON)
 *   - "Delete my account" with a 48h undo window (POST /account/delete,
 *     /account/delete/cancel)
 *
 * The typed email is the re-authentication step: the server HMACs it and
 * compares it with the stored identity (ADR 0002), so the client only checks
 * that something was typed. Account owners are blocked up front here (UX
 * only) and again server-side (UserDeletionGuard, 409 owns_accounts).
 */

import { Button, InlineNotice, Section, TextField } from '@votepit/ui/ds'
import { AlertTriangle, Download, Trash2, Undo2 } from 'lucide-react'
import { useState } from 'react'
import type { ApiError } from '../lib/api'
import { cancelUserDeletion, downloadMyData, requestUserDeletion } from '../lib/api'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

interface PersonalDataSectionProps {
  /** Pending self-deletion deadline (ISO 8601), or null. */
  deletionScheduledAt: string | null
  /** The user owns an account — deletion is blocked until it is gone. */
  ownsAccount: boolean
  onDeletionScheduledChange: (deadline: string | null) => void
}

function errorMessage(err: unknown, fallback: string): string {
  return (err as ApiError)?.payload?.message ?? fallback
}

export function PersonalDataSection({
  deletionScheduledAt,
  ownsAccount,
  onDeletionScheduledChange,
}: PersonalDataSectionProps) {
  const t = useT('profilePage')
  const { language } = useI18n()

  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [email, setEmail] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [undoing, setUndoing] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleDownload = async () => {
    setDownloadError(null)
    setDownloading(true)
    try {
      await downloadMyData()
    } catch (err) {
      setDownloadError(errorMessage(err, t('myDataDownloadFailed')))
    } finally {
      setDownloading(false)
    }
  }

  const handleDelete = async () => {
    setError(null)
    setSubmitting(true)
    try {
      const result = await requestUserDeletion(email.trim())
      setConfirmOpen(false)
      setEmail('')
      onDeletionScheduledChange(result.deletion_scheduled_at)
    } catch (err) {
      setError(errorMessage(err, t('deleteMeFailed')))
    } finally {
      setSubmitting(false)
    }
  }

  const handleUndo = async () => {
    setError(null)
    setUndoing(true)
    try {
      await cancelUserDeletion()
      onDeletionScheduledChange(null)
    } catch (err) {
      setError(errorMessage(err, t('deleteMeUndoFailed')))
    } finally {
      setUndoing(false)
    }
  }

  return (
    <>
      <Section
        testId="personal-data-export-section"
        title={t('myDataHeading')}
        icon={<Download size={16} />}
        description={t('myDataBody')}
      >
        {downloadError !== null && (
          <InlineNotice tone="error" testId="personal-data-download-error" className="mb-ds-md">
            {downloadError}
          </InlineNotice>
        )}
        <Button
          testId="personal-data-download"
          type="button"
          variant="secondary"
          className="gap-ds-xs"
          onClick={() => void handleDownload()}
          disabled={downloading}
          loading={downloading}
        >
          {!downloading && <Download size={14} aria-hidden="true" />}
          {downloading ? t('myDataDownloading') : t('myDataDownload')}
        </Button>
      </Section>

      <Section
        testId="personal-data-delete-section"
        title={t('deleteMeHeading')}
        icon={<AlertTriangle size={16} />}
        description={t('deleteMeBody')}
        emphasis="danger"
      >
        {error !== null && (
          <InlineNotice tone="error" testId="personal-data-error" className="mb-ds-sm">
            {error}
          </InlineNotice>
        )}

        {deletionScheduledAt !== null ? (
          <InlineNotice
            tone="error"
            role="alert"
            testId="personal-data-pending"
            title={t('deleteMePendingTitle')}
            action={
              <Button
                testId="personal-data-undo"
                type="button"
                variant="secondary"
                className="gap-ds-xs"
                onClick={() => void handleUndo()}
                disabled={undoing}
                loading={undoing}
              >
                {!undoing && <Undo2 size={14} aria-hidden="true" />}
                {undoing ? t('deleteMeUndoing') : t('deleteMeUndo')}
              </Button>
            }
          >
            {t('deleteMePendingBody', { date: formatDate(deletionScheduledAt, language) })}
          </InlineNotice>
        ) : !confirmOpen ? (
          <>
            {ownsAccount && (
              <InlineNotice tone="info" testId="personal-data-owner-blocked" className="mb-ds-sm">
                {t('deleteMeOwnerBlocked')}
              </InlineNotice>
            )}
            <Button
              testId="personal-data-delete-open"
              type="button"
              variant="destructive"
              className="gap-ds-xs"
              onClick={() => setConfirmOpen(true)}
              disabled={ownsAccount}
            >
              <Trash2 size={14} aria-hidden="true" />
              {t('deleteMeButton')}
            </Button>
          </>
        ) : (
          <div className="flex flex-col gap-ds-sm max-w-ds-field">
            <TextField
              className="max-w-ds-field"
              testId="personal-data-delete-confirm-email"
              label={t('deleteMeConfirmLabel')}
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="email"
            />
            <div className="flex flex-wrap gap-ds-xs">
              <Button
                testId="personal-data-delete-submit"
                type="button"
                variant="destructive"
                onClick={() => void handleDelete()}
                disabled={submitting || email.trim() === ''}
                loading={submitting}
              >
                {submitting ? t('deleteMeSubmitting') : t('deleteMeConfirmSubmit')}
              </Button>
              <Button
                testId="personal-data-delete-cancel"
                type="button"
                variant="secondary"
                onClick={() => {
                  setConfirmOpen(false)
                  setEmail('')
                  setError(null)
                }}
                disabled={submitting}
              >
                {t('deleteMeCancel')}
              </Button>
            </div>
          </div>
        )}
      </Section>
    </>
  )
}

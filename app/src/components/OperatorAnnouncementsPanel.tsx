/**
 * OperatorAnnouncementsPanel — broadcast-announcement management section of
 * OperatorPage. Self-contained: loads its own data on mount and reloads
 * itself after every mutation. Posting an announcement here puts it into
 * every customer's inbox immediately (migrations/
 * 0024_add_notifications_remove_support_email.sql) — no edit, only
 * create/delete (announcements are a one-way broadcast, not a living doc).
 */

import {
  Alert,
  Button,
  ConfirmDialog,
  EmptyState,
  LoadingState,
  Section,
  Textarea,
  TextInput,
} from '@votepit/ui'
import { Megaphone, Plus, Trash2, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { AnnouncementSummary, ApiError, CreateAnnouncementPayload } from '../lib/api'
import {
  createOperatorAnnouncement,
  deleteOperatorAnnouncement,
  listOperatorAnnouncements,
} from '../lib/api'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

const emptyForm: CreateAnnouncementPayload = { title: '', body: '', link_path: '' }

export function OperatorAnnouncementsPanel() {
  const t = useT('operatorPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [announcements, setAnnouncements] = useState<AnnouncementSummary[]>([])
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [deleteBusyId, setDeleteBusyId] = useState<number | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{ id: number; title: string } | null>(null)

  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState<CreateAnnouncementPayload>(emptyForm)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const reload = async () => {
    const data = await listOperatorAnnouncements()
    setAnnouncements(data.announcements)
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline, no external deps worth tracking).
  useEffect(() => {
    let cancelled = false
    reload()
      .catch((err) => {
        if (cancelled) return
        const apiErr = err as ApiError
        setError(apiErr?.payload?.message ?? t('announcementsLoadError'))
      })
      .finally(() => {
        if (!cancelled) setLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [t])

  const startCreate = () => {
    setCreating(true)
    setForm(emptyForm)
    setFieldErrors({})
  }

  const cancelCreate = () => {
    setCreating(false)
    setFieldErrors({})
  }

  const handleSave = async () => {
    if (busy) return
    setBusy(true)
    setFieldErrors({})
    setError(null)
    try {
      await createOperatorAnnouncement({
        title: form.title,
        body: form.body,
        link_path: form.link_path?.trim() ? form.link_path.trim() : undefined,
      })
      setCreating(false)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      if (apiErr?.payload?.fields !== undefined) {
        setFieldErrors(apiErr.payload.fields)
      } else {
        setError(apiErr?.payload?.message ?? t('announcementsSaveFailed'))
      }
    } finally {
      setBusy(false)
    }
  }

  const handleDeleteConfirm = async () => {
    if (deleteTarget === null || deleteBusyId !== null) return
    const { id } = deleteTarget
    setDeleteBusyId(id)
    setError(null)
    try {
      await deleteOperatorAnnouncement(id)
      setDeleteTarget(null)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setDeleteTarget(null)
      setError(apiErr?.payload?.message ?? t('announcementsDeleteFailed'))
    } finally {
      setDeleteBusyId(null)
    }
  }

  const heading = t('announcementsHeading', { count: announcements.length })

  return (
    <>
      <Section
        title={heading}
        icon={<Megaphone size={16} />}
        flush
        footer={
          !loaded ? undefined : creating ? (
            <>
              <Button
                variant="primary"
                size="sm"
                onClick={() => void handleSave()}
                disabled={busy}
                loading={busy}
              >
                {t('announcementPublish')}
              </Button>
              <Button
                variant="ghost"
                size="sm"
                onClick={cancelCreate}
                disabled={busy}
                icon={<X size={13} />}
              >
                {tCommon('action.cancel')}
              </Button>
            </>
          ) : (
            <Button variant="secondary" size="sm" onClick={startCreate} icon={<Plus size={14} />}>
              {t('announcementsCreate')}
            </Button>
          )
        }
      >
        {!loaded ? (
          <LoadingState label={t('announcementsLoading')} rows={2} className="px-4 sm:px-5" />
        ) : (
          <>
            {error !== null && (
              <div className="px-4 sm:px-5 pt-4">
                <Alert tone="error">{error}</Alert>
              </div>
            )}

            {announcements.length === 0 && !creating ? (
              <EmptyState size="compact" headingLevel={3} title={t('announcementsNone')} />
            ) : (
              <ul className="divide-y divide-vp-border-subtle" aria-label={heading}>
                {announcements.map((a) => (
                  <li key={a.id} className="flex flex-col gap-1.5 px-4 sm:px-5 py-3.5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-medium text-vp-ink">{a.title}</span>
                      <Button
                        variant="ghost-danger"
                        size="sm"
                        onClick={() => setDeleteTarget({ id: a.id, title: a.title })}
                        disabled={deleteBusyId !== null}
                        aria-label={t('announcementDeleteAriaLabel', { title: a.title })}
                        icon={<Trash2 size={13} />}
                      >
                        {t('announcementDelete')}
                      </Button>
                    </div>
                    <p className="vp-prose text-vp-sm text-vp-text-secondary">{a.body}</p>
                    <p className="text-vp-xs text-vp-text-muted font-mono-num">
                      {formatDate(a.created_at, language)}
                    </p>
                  </li>
                ))}
              </ul>
            )}

            {creating && (
              <div className="flex flex-col gap-3 px-4 sm:px-5 py-4 border-t border-vp-border-subtle">
                <TextInput
                  label={t('announcementTitleLabel')}
                  value={form.title}
                  onChange={(v) => setForm((prev) => ({ ...prev, title: v }))}
                  error={fieldErrors.title}
                />
                <Textarea
                  label={t('announcementBodyLabel')}
                  value={form.body}
                  onChange={(v) => setForm((prev) => ({ ...prev, body: v }))}
                  error={fieldErrors.body}
                  rows={3}
                />
                <TextInput
                  label={t('announcementLinkPathLabel')}
                  hint={t('announcementLinkPathHint')}
                  value={form.link_path ?? ''}
                  onChange={(v) => setForm((prev) => ({ ...prev, link_path: v }))}
                  error={fieldErrors.link_path}
                />
              </div>
            )}
          </>
        )}
      </Section>

      <ConfirmDialog
        open={deleteTarget !== null}
        title={
          deleteTarget !== null
            ? t('announcementDeleteAriaLabel', { title: deleteTarget.title })
            : ''
        }
        description={t('announcementConfirmDelete')}
        confirmLabel={t('announcementDelete')}
        cancelLabel={tCommon('action.cancel')}
        tone="danger"
        busy={deleteTarget !== null && deleteBusyId === deleteTarget.id}
        onConfirm={() => void handleDeleteConfirm()}
        onCancel={() => setDeleteTarget(null)}
      />
    </>
  )
}

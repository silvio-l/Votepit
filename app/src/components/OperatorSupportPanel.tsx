/**
 * OperatorSupportPanel — the support-ticket inbox section of OperatorPage.
 * Self-contained: loads its own data on mount and reloads itself after every
 * mutation, so OperatorPage's own reload() doesn't need to know about it.
 */

import {
  Alert,
  Badge,
  type BadgeTone,
  Button,
  EmptyState,
  Section,
  Select,
  Textarea,
} from '@votepit/ui'
import { LifeBuoy } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ApiError, SupportRequestStatus, SupportRequestSummary } from '../lib/api'
import {
  listOperatorSupportRequests,
  replyOperatorSupportRequest,
  setOperatorSupportRequestStatus,
} from '../lib/api'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

const statusTone: Record<SupportRequestStatus, BadgeTone> = {
  open: 'warning',
  answered: 'success',
  closed: 'neutral',
}

const STATUSES: SupportRequestStatus[] = ['open', 'answered', 'closed']

export function OperatorSupportPanel() {
  const t = useT('operatorPage')
  const { language } = useI18n()

  const [requests, setRequests] = useState<SupportRequestSummary[]>([])
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [replyDrafts, setReplyDrafts] = useState<Record<number, string>>({})

  const reload = async () => {
    const data = await listOperatorSupportRequests()
    setRequests(data.requests)
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline, no external deps worth tracking).
  useEffect(() => {
    let cancelled = false
    reload()
      .catch((err) => {
        if (cancelled) return
        const apiErr = err as ApiError
        setError(apiErr?.payload?.message ?? t('supportLoadError'))
      })
      .finally(() => {
        if (!cancelled) setLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [t])

  const handleReply = async (id: number) => {
    const reply = (replyDrafts[id] ?? '').trim()
    if (reply === '' || busyId !== null) return
    setBusyId(id)
    setError(null)
    try {
      await replyOperatorSupportRequest(id, reply)
      setReplyDrafts((prev) => ({ ...prev, [id]: '' }))
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('supportReplyFailed'))
    } finally {
      setBusyId(null)
    }
  }

  const handleStatusChange = async (id: number, status: SupportRequestStatus) => {
    if (busyId !== null) return
    setBusyId(id)
    setError(null)
    try {
      await setOperatorSupportRequestStatus(id, status)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('supportStatusFailed'))
    } finally {
      setBusyId(null)
    }
  }

  if (!loaded) return null

  return (
    <Section
      title={
        <span className="inline-flex items-center gap-1.5">
          <LifeBuoy size={15} aria-hidden="true" className="text-vp-text-secondary" />
          {t('supportHeading', { count: requests.length })}
        </span>
      }
      flush
    >
      {error !== null && (
        <div className="px-4 sm:px-5 pt-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}
      {requests.length === 0 ? (
        <EmptyState size="compact" title={t('supportNone')} />
      ) : (
        <ul
          className="divide-y divide-vp-border-subtle"
          aria-label={t('supportHeading', { count: requests.length })}
        >
          {requests.map((r) => (
            <li key={r.id} className="flex flex-col gap-2 px-4 sm:px-5 py-3.5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex flex-wrap items-center gap-2 min-w-0">
                  <span className="font-medium text-vp-ink">{r.subject}</span>
                  <Badge tone="neutral">{t(`category.${r.category}`)}</Badge>
                  <Badge tone={statusTone[r.status]}>{t(`status.${r.status}`)}</Badge>
                </span>
                <Select
                  label={t('supportStatusAriaLabel', { id: r.id })}
                  hideLabel
                  size="sm"
                  value={r.status}
                  onChange={(v) => void handleStatusChange(r.id, v as SupportRequestStatus)}
                  disabled={busyId === r.id}
                  className="w-32"
                >
                  {STATUSES.map((s) => (
                    <option key={s} value={s}>
                      {t(`status.${s}`)}
                    </option>
                  ))}
                </Select>
              </div>
              <p className="vp-prose text-vp-sm text-vp-text-secondary">{r.message}</p>
              <p className="text-vp-xs text-vp-text-muted">
                {t('supportAccountLabel', { id: r.account_id })}
                {' · '}
                {formatDate(r.created_at, language)}
              </p>

              {r.operator_reply !== null && (
                <div className="rounded-vp-md border border-vp-border-subtle bg-vp-surface-frost px-3 py-2.5">
                  <p className="text-vp-xs font-medium text-vp-ink mb-1">
                    {t('supportReplyLabel')}
                  </p>
                  <p className="vp-prose text-vp-sm text-vp-text-secondary">{r.operator_reply}</p>
                </div>
              )}

              <div className="flex flex-col sm:flex-row sm:items-end gap-2">
                <div className="flex-1">
                  <Textarea
                    label={t('supportReplyDraftLabel')}
                    value={replyDrafts[r.id] ?? ''}
                    onChange={(v) => setReplyDrafts((prev) => ({ ...prev, [r.id]: v }))}
                    placeholder={t('supportReplyPlaceholder')}
                    disabled={busyId === r.id}
                    rows={2}
                  />
                </div>
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => void handleReply(r.id)}
                  disabled={busyId === r.id || (replyDrafts[r.id] ?? '').trim() === ''}
                >
                  {t('supportReplySubmit')}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Section>
  )
}

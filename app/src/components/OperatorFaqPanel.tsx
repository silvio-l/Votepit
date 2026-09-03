/**
 * OperatorFaqPanel — FAQ knowledge-base management section of OperatorPage.
 * Self-contained: loads its own data on mount and reloads itself after every
 * mutation. Entries feed both the standalone FAQ view and SupportPage's
 * category-based deflection (see migrations/0023_add_support_and_faq.sql).
 */

import {
  Alert,
  Badge,
  Button,
  Checkbox,
  EmptyState,
  Section,
  Select,
  Textarea,
  TextInput,
} from '@votepit/ui'
import { HelpCircle, Pencil, Plus, Trash2, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ApiError, FaqEntry, FaqEntryPayload, SupportCategory } from '../lib/api'
import { createFaqEntry, deleteFaqEntry, listOperatorFaq, updateFaqEntry } from '../lib/api'
import { useT } from '../lib/i18n/context'

const CATEGORIES: SupportCategory[] = [
  'technical',
  'billing',
  'account',
  'feature_request',
  'privacy',
  'other',
]

const emptyForm: FaqEntryPayload = {
  category: 'technical',
  question_de: '',
  question_en: '',
  answer_de: '',
  answer_en: '',
  sort_order: 0,
  is_published: true,
}

export function OperatorFaqPanel() {
  const t = useT('operatorPage')

  const [entries, setEntries] = useState<FaqEntry[]>([])
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [deleteBusyId, setDeleteBusyId] = useState<number | null>(null)

  const [editingId, setEditingId] = useState<number | 'new' | null>(null)
  const [form, setForm] = useState<FaqEntryPayload>(emptyForm)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const reload = async () => {
    const data = await listOperatorFaq()
    setEntries(data.entries)
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline, no external deps worth tracking).
  useEffect(() => {
    let cancelled = false
    reload()
      .catch((err) => {
        if (cancelled) return
        const apiErr = err as ApiError
        setError(apiErr?.payload?.message ?? t('faqLoadError'))
      })
      .finally(() => {
        if (!cancelled) setLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [t])

  const startCreate = () => {
    setEditingId('new')
    setForm(emptyForm)
    setFieldErrors({})
  }

  const startEdit = (entry: FaqEntry) => {
    setEditingId(entry.id)
    setForm({
      category: entry.category,
      question_de: entry.question_de,
      question_en: entry.question_en,
      answer_de: entry.answer_de,
      answer_en: entry.answer_en,
      sort_order: entry.sort_order,
      is_published: entry.is_published ?? true,
    })
    setFieldErrors({})
  }

  const cancelEdit = () => {
    setEditingId(null)
    setFieldErrors({})
  }

  const handleSave = async () => {
    if (busy || editingId === null) return
    setBusy(true)
    setFieldErrors({})
    setError(null)
    try {
      if (editingId === 'new') {
        await createFaqEntry(form)
      } else {
        await updateFaqEntry(editingId, form)
      }
      setEditingId(null)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      if (apiErr?.payload?.fields !== undefined) {
        setFieldErrors(apiErr.payload.fields)
      } else {
        setError(apiErr?.payload?.message ?? t('faqSaveFailed'))
      }
    } finally {
      setBusy(false)
    }
  }

  const handleDelete = async (id: number) => {
    if (deleteBusyId !== null) return
    setDeleteBusyId(id)
    setError(null)
    try {
      await deleteFaqEntry(id)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('faqDeleteFailed'))
    } finally {
      setDeleteBusyId(null)
    }
  }

  if (!loaded) return null

  return (
    <Section
      title={
        <span className="inline-flex items-center gap-1.5">
          <HelpCircle size={15} aria-hidden="true" className="text-vp-text-secondary" />
          {t('faqHeading', { count: entries.length })}
        </span>
      }
      flush
    >
      {error !== null && (
        <div className="px-4 sm:px-5 pt-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}

      {entries.length === 0 && editingId === null ? (
        <EmptyState size="compact" title={t('faqNone')} />
      ) : (
        <ul
          className="divide-y divide-vp-border-subtle"
          aria-label={t('faqHeading', { count: entries.length })}
        >
          {entries.map((entry) =>
            editingId === entry.id ? (
              <li key={entry.id} className="px-4 sm:px-5 py-4">
                {faqForm(form, setForm, fieldErrors, t)}
                {formActions(handleSave, cancelEdit, busy, t)}
              </li>
            ) : (
              <li key={entry.id} className="flex flex-col gap-1.5 px-4 sm:px-5 py-3.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="flex flex-wrap items-center gap-2 min-w-0">
                    <span className="font-medium text-vp-ink">{entry.question_de}</span>
                    <Badge tone="neutral">{t(`category.${entry.category}`)}</Badge>
                    {entry.is_published === false && (
                      <Badge tone="warning">{t('faqDraftBadge')}</Badge>
                    )}
                  </span>
                  <span className="inline-flex items-center gap-1 shrink-0">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => startEdit(entry)}
                      aria-label={t('faqEditAriaLabel', { question: entry.question_de })}
                      className="gap-1.5"
                    >
                      <Pencil size={13} aria-hidden="true" />
                      {t('faqEdit')}
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => void handleDelete(entry.id)}
                      disabled={deleteBusyId === entry.id}
                      aria-label={t('faqDeleteAriaLabel', { question: entry.question_de })}
                      className="text-vp-vote-down-strong gap-1.5"
                    >
                      <Trash2 size={13} aria-hidden="true" />
                      {t('faqDelete')}
                    </Button>
                  </span>
                </div>
                <p className="vp-prose text-vp-sm text-vp-text-secondary">{entry.answer_de}</p>
              </li>
            ),
          )}
        </ul>
      )}

      {editingId === 'new' ? (
        <div className="px-4 sm:px-5 py-4 border-t border-vp-border-subtle">
          {faqForm(form, setForm, fieldErrors, t)}
          {formActions(handleSave, cancelEdit, busy, t)}
        </div>
      ) : (
        <div className="px-4 sm:px-5 py-4 border-t border-vp-border-subtle">
          <Button variant="secondary" size="sm" onClick={startCreate} className="gap-1.5">
            <Plus size={14} aria-hidden="true" />
            {t('faqCreate')}
          </Button>
        </div>
      )}
    </Section>
  )
}

function faqForm(
  form: FaqEntryPayload,
  setForm: (updater: (prev: FaqEntryPayload) => FaqEntryPayload) => void,
  fieldErrors: Record<string, string>,
  t: (key: string, vars?: Record<string, string | number>) => string,
) {
  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Select
          label={t('categoryLabel')}
          value={form.category}
          onChange={(v) => setForm((prev) => ({ ...prev, category: v as SupportCategory }))}
          error={fieldErrors.category}
        >
          {CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {t(`category.${c}`)}
            </option>
          ))}
        </Select>
        <TextInput
          label={t('faqSortOrderLabel')}
          type="number"
          value={String(form.sort_order)}
          onChange={(v) => setForm((prev) => ({ ...prev, sort_order: Number(v) || 0 }))}
        />
      </div>
      <TextInput
        label={t('faqQuestionDeLabel')}
        value={form.question_de}
        onChange={(v) => setForm((prev) => ({ ...prev, question_de: v }))}
        error={fieldErrors.question_de}
      />
      <TextInput
        label={t('faqQuestionEnLabel')}
        value={form.question_en}
        onChange={(v) => setForm((prev) => ({ ...prev, question_en: v }))}
        error={fieldErrors.question_en}
      />
      <Textarea
        label={t('faqAnswerDeLabel')}
        value={form.answer_de}
        onChange={(v) => setForm((prev) => ({ ...prev, answer_de: v }))}
        error={fieldErrors.answer_de}
        rows={3}
      />
      <Textarea
        label={t('faqAnswerEnLabel')}
        value={form.answer_en}
        onChange={(v) => setForm((prev) => ({ ...prev, answer_en: v }))}
        error={fieldErrors.answer_en}
        rows={3}
      />
      <Checkbox
        label={t('faqPublishedLabel')}
        checked={form.is_published}
        onChange={(v) => setForm((prev) => ({ ...prev, is_published: v }))}
      />
    </div>
  )
}

function formActions(
  onSave: () => void,
  onCancel: () => void,
  busy: boolean,
  t: (key: string) => string,
) {
  return (
    <div className="flex items-center gap-2 mt-4">
      <Button variant="primary" size="sm" onClick={onSave} disabled={busy} loading={busy}>
        {t('faqSave')}
      </Button>
      <Button variant="ghost" size="sm" onClick={onCancel} disabled={busy} className="gap-1.5">
        <X size={13} aria-hidden="true" />
        {t('faqCancel')}
      </Button>
    </div>
  )
}

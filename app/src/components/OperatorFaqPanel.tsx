/**
 * OperatorFaqPanel — FAQ knowledge-base management section of OperatorPage.
 * Self-contained: loads its own data on mount and reloads itself after every
 * mutation. Entries feed both the standalone FAQ view and SupportPage's
 * category-based deflection (see migrations/0023_add_support_and_faq.sql).
 */

import {
  Badge,
  Button,
  Checkbox,
  ConfirmDialog,
  EmptyState,
  InlineNotice,
  LoadingState,
  Section,
  Select,
  TextArea,
  TextField,
} from '@votepit/ui/ds'
import { HelpCircle } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ApiError, FaqEntry, FaqEntryPayload, SupportCategory } from '../lib/api'
import { createFaqEntry, deleteFaqEntry, listOperatorFaq, updateFaqEntry } from '../lib/api'
import type { Language } from '../lib/i18n/context'
import { useI18n, useT } from '../lib/i18n/context'

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

/**
 * Pick the field matching the UI language (FAQ entries are authored in de/en
 * only, so every other UI language reads the English one), falling back to
 * the other field so a half-translated entry never renders as an empty row.
 */
function localized(language: Language, de: string, en: string): string {
  return language === 'de' ? de || en : en || de
}

export function OperatorFaqPanel() {
  const t = useT('operatorPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [entries, setEntries] = useState<FaqEntry[]>([])
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [deleteBusyId, setDeleteBusyId] = useState<number | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{ id: number; question: string } | null>(null)

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

  const handleDeleteConfirm = async () => {
    if (deleteTarget === null || deleteBusyId !== null) return
    const { id } = deleteTarget
    setDeleteBusyId(id)
    setError(null)
    try {
      await deleteFaqEntry(id)
      setDeleteTarget(null)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setDeleteTarget(null)
      setError(apiErr?.payload?.message ?? t('faqDeleteFailed'))
    } finally {
      setDeleteBusyId(null)
    }
  }

  const heading = t('faqHeading', { count: entries.length })

  const formActions = (
    <>
      <Button
        variant="primary"
        testId="faq-save"
        onClick={() => void handleSave()}
        disabled={busy}
        loading={busy}
      >
        {t('faqSave')}
      </Button>
      <Button variant="text" testId="faq-cancel" icon="close" onClick={cancelEdit} disabled={busy}>
        {t('faqCancel')}
      </Button>
    </>
  )

  // The footer hosts the create form's actions or the "new entry" button;
  // inline edits keep their actions next to the row being edited so the
  // buttons stay visually attached to the fields they act on.
  const footer = !loaded ? undefined : editingId === 'new' ? (
    formActions
  ) : editingId === null ? (
    <Button variant="secondary" testId="faq-create" onClick={startCreate}>
      {t('faqCreate')}
    </Button>
  ) : undefined

  return (
    <>
      <Section
        testId="operator-faq-section"
        title={heading}
        icon={<HelpCircle size={16} />}
        flush
        footer={footer}
      >
        {!loaded ? (
          <LoadingState label={t('faqLoading')} rows={3} className="ds-section__inset" />
        ) : (
          <>
            {error !== null && (
              <div className="ds-section__inset">
                <InlineNotice tone="error">{error}</InlineNotice>
              </div>
            )}

            {entries.length === 0 && editingId === null ? (
              <EmptyState
                kind="no-ideas"
                variant="plain"
                title={t('faqNone')}
                testId="operator-faq-empty"
              />
            ) : (
              <ul className="ds-section-list" aria-label={heading}>
                {entries.map((entry) => {
                  const question = localized(language, entry.question_de, entry.question_en)
                  const answer = localized(language, entry.answer_de, entry.answer_en)
                  return editingId === entry.id ? (
                    <li key={entry.id}>
                      {faqForm(form, setForm, fieldErrors, t)}
                      <div className="flex items-center gap-2 mt-4">{formActions}</div>
                    </li>
                  ) : (
                    <li key={entry.id} className="flex flex-col gap-1.5">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="flex flex-wrap items-center gap-2 min-w-0">
                          <span className="font-medium text-vp-title">{question}</span>
                          <Badge tone="neutral">{t(`category.${entry.category}`)}</Badge>
                          {entry.is_published === false && (
                            <Badge tone="warning">{t('faqDraftBadge')}</Badge>
                          )}
                        </span>
                        <span className="inline-flex items-center gap-1 shrink-0">
                          <Button
                            variant="text"
                            testId={`faq-edit-${entry.id}`}
                            onClick={() => startEdit(entry)}
                            aria-label={t('faqEditAriaLabel', { question })}
                            icon="pencil"
                          >
                            {t('faqEdit')}
                          </Button>
                          <Button
                            variant="text-destructive"
                            testId={`faq-delete-${entry.id}`}
                            onClick={() => setDeleteTarget({ id: entry.id, question })}
                            disabled={deleteBusyId !== null}
                            aria-label={t('faqDeleteAriaLabel', { question })}
                          >
                            {t('faqDelete')}
                          </Button>
                        </span>
                      </div>
                      <p className="vp-prose text-vp-sm text-vp-text-secondary">{answer}</p>
                    </li>
                  )
                })}
              </ul>
            )}

            {editingId === 'new' && (
              <div className="ds-section__inset border-t border-vp-border-subtle">
                {faqForm(form, setForm, fieldErrors, t)}
              </div>
            )}
          </>
        )}
      </Section>

      <ConfirmDialog
        testId="operator-faq-delete-dialog"
        open={deleteTarget !== null}
        title={
          deleteTarget !== null ? t('faqDeleteAriaLabel', { question: deleteTarget.question }) : ''
        }
        description={t('faqConfirmDelete')}
        confirmLabel={t('faqDelete')}
        cancelLabel={tCommon('action.cancel')}
        tone="danger"
        busy={deleteTarget !== null && deleteBusyId === deleteTarget.id}
        onConfirm={() => void handleDeleteConfirm()}
        onCancel={() => setDeleteTarget(null)}
      />
    </>
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
          testId="operator-faq-category"
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
        <TextField
          testId="operator-faq-sort-order"
          label={t('faqSortOrderLabel')}
          type="number"
          value={String(form.sort_order)}
          onChange={(e) =>
            setForm((prev) => ({ ...prev, sort_order: Number(e.target.value) || 0 }))
          }
        />
      </div>
      <TextField
        testId="operator-faq-question-de"
        label={t('faqQuestionDeLabel')}
        value={form.question_de}
        onChange={(e) => setForm((prev) => ({ ...prev, question_de: e.target.value }))}
        error={fieldErrors.question_de}
      />
      <TextField
        testId="operator-faq-question-en"
        label={t('faqQuestionEnLabel')}
        value={form.question_en}
        onChange={(e) => setForm((prev) => ({ ...prev, question_en: e.target.value }))}
        error={fieldErrors.question_en}
      />
      <TextArea
        testId="operator-faq-answer-de"
        label={t('faqAnswerDeLabel')}
        value={form.answer_de}
        onChange={(e) => setForm((prev) => ({ ...prev, answer_de: e.target.value }))}
        error={fieldErrors.answer_de}
        rows={3}
      />
      <TextArea
        testId="operator-faq-answer-en"
        label={t('faqAnswerEnLabel')}
        value={form.answer_en}
        onChange={(e) => setForm((prev) => ({ ...prev, answer_en: e.target.value }))}
        error={fieldErrors.answer_en}
        rows={3}
      />
      <Checkbox
        testId="operator-faq-published"
        label={t('faqPublishedLabel')}
        checked={form.is_published}
        onChange={(v) => setForm((prev) => ({ ...prev, is_published: v }))}
      />
    </div>
  )
}

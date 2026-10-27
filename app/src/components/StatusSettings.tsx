import { Badge, Button, Checkbox, InlineNotice, Section, Select, TextField } from '@votepit/ui/ds'
import { ListChecks } from 'lucide-react'
import { useState } from 'react'
import type { ApiError, BoardStatusInfo, IdeaStatus } from '../lib/api'
import {
  createAdminStatus,
  deleteAdminStatus,
  getAdminStatuses,
  updateAdminStatus,
} from '../lib/api'
import { STATUS_CATEGORIES } from '../lib/boardStatuses'
import { useT } from '../lib/i18n/context'

/** Mirror of BoardStatuses::MAX_STATUSES — the server enforces the real cap. */
const MAX_STATUSES = 12

interface Draft {
  label_de: string
  label_en: string
  is_hidden: boolean
  allow_votes: boolean
  allow_comments: boolean
}

function draftOf(s: BoardStatusInfo): Draft {
  return {
    label_de: s.label_de ?? '',
    label_en: s.label_en ?? '',
    is_hidden: s.is_hidden,
    allow_votes: s.allow_votes,
    allow_comments: s.allow_comments,
  }
}

function errorText(err: unknown, fallback: string): string {
  const apiErr = err as ApiError
  const firstField = Object.values(apiErr?.payload?.fields ?? {})[0]
  return firstField ?? apiErr?.payload?.message ?? fallback
}

/**
 * Board settings → statuses (broaden-use-cases issue 03). Rename the six
 * system statuses, hide them, close them for votes/comments, and add up to
 * MAX_STATUSES owner-defined statuses inside the six fixed categories.
 * Every label is plaintext (server-validated, rendered as text only).
 * accountAdmin only — the same gate as the rest of AdminPage.
 */
export function StatusSettings({ boardSlug }: { boardSlug: string }) {
  const t = useT('adminPage')
  const tCommon = useT('common')

  const categoryLabel: Record<IdeaStatus, string> = {
    open: tCommon('status.open'),
    planned: tCommon('status.planned'),
    in_progress: tCommon('status.inProgress'),
    done: tCommon('status.done'),
    declined: tCommon('status.declined'),
    archived: tCommon('status.archived'),
  }

  const [statuses, setStatuses] = useState<BoardStatusInfo[] | null>(null)
  const [drafts, setDrafts] = useState<Record<string, Draft>>({})
  const [moveTo, setMoveTo] = useState<Record<string, string>>({})
  const [loadError, setLoadError] = useState(false)
  const [pendingKey, setPendingKey] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState<string | null>(null)

  const [newCategory, setNewCategory] = useState<IdeaStatus>('planned')
  const [newDe, setNewDe] = useState('')
  const [newEn, setNewEn] = useState('')

  // Loaded on demand: most owners never touch statuses, so the settings
  // page does not pay a request for them on every visit.
  const [expanded, setExpanded] = useState(false)

  const apply = (next: BoardStatusInfo[]) => {
    setStatuses(next)
    setDrafts(Object.fromEntries(next.map((s) => [s.key, draftOf(s)])))
  }

  const handleExpand = () => {
    setExpanded(true)
    setLoadError(false)
    getAdminStatuses(boardSlug)
      .then((res) => apply(res.statuses))
      .catch(() => setLoadError(true))
  }

  const run = async (key: string, action: () => Promise<{ statuses: BoardStatusInfo[] }>) => {
    if (pendingKey !== null) return false
    setPendingKey(key)
    setError(null)
    setSaved(null)
    try {
      apply((await action()).statuses)
      setSaved(t('statusesSaved'))
      return true
    } catch (err) {
      setError(errorText(err, t('statusesSaveError')))
      return false
    } finally {
      setPendingKey(null)
    }
  }

  const patch = (key: string, change: Partial<Draft>) =>
    setDrafts((prev) => ({ ...prev, [key]: { ...prev[key], ...change } }))

  const handleSave = (s: BoardStatusInfo) => {
    const d = drafts[s.key]
    void run(s.key, () =>
      updateAdminStatus(boardSlug, s.key, {
        label_de: d.label_de.trim() === '' ? null : d.label_de,
        label_en: d.label_en.trim() === '' ? null : d.label_en,
        is_hidden: d.is_hidden,
        allow_votes: d.allow_votes,
        allow_comments: d.allow_comments,
      }),
    )
  }

  const handleDelete = (s: BoardStatusInfo) => {
    const target = moveTo[s.key]
    void run(s.key, () => deleteAdminStatus(boardSlug, s.key, target || undefined))
  }

  const handleCreate = async () => {
    const ok = await run('__new', () =>
      createAdminStatus(boardSlug, {
        category: newCategory,
        label_de: newDe.trim() === '' ? null : newDe,
        label_en: newEn.trim() === '' ? null : newEn,
      }),
    )
    if (ok) {
      setNewDe('')
      setNewEn('')
    }
  }

  return (
    <Section
      testId="status-settings-section"
      title={t('statusesHeading')}
      icon={<ListChecks size={16} />}
      description={t('statusesSubtitle')}
      flush
    >
      {!expanded ? (
        <div className="ds-section__inset">
          <Button
            testId="status-settings-manage"
            type="button"
            variant="secondary"
            onClick={handleExpand}
          >
            {t('statusesManage')}
          </Button>
        </div>
      ) : loadError ? (
        <div className="ds-section__inset">
          <InlineNotice tone="error" testId="status-settings-load-error">
            {t('statusesLoadError')}
          </InlineNotice>
        </div>
      ) : statuses === null ? (
        <p className="ds-section__inset text-vp-sm text-vp-text-muted">{t('loading')}</p>
      ) : (
        <>
          <ul className="ds-section-list">
            {statuses.map((s) => {
              const d = drafts[s.key] ?? draftOf(s)
              const testSlug = s.key.replace(/_/g, '-')
              const busy = pendingKey !== null
              const count = s.idea_count ?? 0
              const sameCategory = statuses.filter(
                (o) => o.category === s.category && o.key !== s.key && !o.is_hidden,
              )
              return (
                <li key={s.key} className="flex flex-col gap-ds-sm">
                  <div className="flex flex-wrap items-center gap-ds-xs">
                    <span className="text-vp-sm font-semibold text-vp-ink">
                      {categoryLabel[s.category]}
                    </span>
                    <Badge>{s.is_system ? t('statusesSystem') : t('statusesCustom')}</Badge>
                    <span className="text-vp-xs text-vp-text-muted">
                      {t('statusesIdeaCount', { count })}
                    </span>
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-ds-sm">
                    <TextField
                      className="max-w-ds-field"
                      testId={`status-${testSlug}-label-de`}
                      label={t('statusesLabelDe')}
                      value={d.label_de}
                      onChange={(e) => patch(s.key, { label_de: e.target.value })}
                      maxLength={40}
                      placeholder={s.is_system ? categoryLabel[s.category] : ''}
                      disabled={busy}
                    />
                    <TextField
                      className="max-w-ds-field"
                      testId={`status-${testSlug}-label-en`}
                      label={t('statusesLabelEn')}
                      value={d.label_en}
                      onChange={(e) => patch(s.key, { label_en: e.target.value })}
                      maxLength={40}
                      placeholder={s.is_system ? categoryLabel[s.category] : ''}
                      disabled={busy}
                    />
                  </div>
                  <div className="flex flex-wrap gap-x-ds-md gap-y-ds-xs">
                    {s.key !== 'open' && (
                      <Checkbox
                        testId={`status-settings-hidden-${s.key}`}
                        id={`status-hidden-${s.key}`}
                        label={t('statusesHidden')}
                        checked={d.is_hidden}
                        onChange={(v) => patch(s.key, { is_hidden: v })}
                        disabled={busy}
                      />
                    )}
                    <Checkbox
                      testId={`status-settings-votes-${s.key}`}
                      id={`status-votes-${s.key}`}
                      label={t('statusesAllowVotes')}
                      checked={d.allow_votes}
                      onChange={(v) => patch(s.key, { allow_votes: v })}
                      disabled={busy}
                    />
                    <Checkbox
                      testId={`status-settings-comments-${s.key}`}
                      id={`status-comments-${s.key}`}
                      label={t('statusesAllowComments')}
                      checked={d.allow_comments}
                      onChange={(v) => patch(s.key, { allow_comments: v })}
                      disabled={busy}
                    />
                  </div>
                  {s.key === 'open' && (!d.allow_votes || !d.allow_comments) && (
                    <InlineNotice tone="warning" testId="status-open-warning">
                      {t('statusesOpenWarning')}
                    </InlineNotice>
                  )}
                  <div className="flex flex-wrap items-end gap-ds-xs">
                    <Button
                      testId={`status-${testSlug}-save`}
                      type="button"
                      onClick={() => handleSave(s)}
                      disabled={busy}
                      loading={pendingKey === s.key}
                    >
                      {t('statusesSave')}
                    </Button>
                    {!s.is_system && count > 0 && (
                      <Select
                        testId={`status-settings-move-to-${s.key}`}
                        label={t('statusesMoveTo')}
                        value={moveTo[s.key] ?? ''}
                        onChange={(v) => setMoveTo((prev) => ({ ...prev, [s.key]: v }))}
                        disabled={busy}
                        className="w-48"
                      >
                        <option value="">{t('statusesMoveToPlaceholder')}</option>
                        {sameCategory.map((o) => (
                          <option key={o.key} value={o.key}>
                            {o.label_de ?? o.label_en ?? categoryLabel[o.category]}
                          </option>
                        ))}
                      </Select>
                    )}
                    {!s.is_system && (
                      <Button
                        testId={`status-${testSlug}-delete`}
                        type="button"
                        variant="secondary"
                        onClick={() => handleDelete(s)}
                        disabled={busy || (count > 0 && !moveTo[s.key])}
                      >
                        {t('statusesDelete')}
                      </Button>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>

          <div className="ds-section__inset flex flex-col gap-ds-sm border-t border-vp-border-subtle">
            <p className="text-vp-sm font-semibold text-vp-ink">{t('statusesAddHeading')}</p>
            {statuses.length >= MAX_STATUSES ? (
              <p className="text-vp-sm text-vp-text-muted">
                {t('statusesLimit', { max: MAX_STATUSES })}
              </p>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-ds-sm items-end">
                <Select
                  className="max-w-ds-field"
                  testId="status-settings-new-category"
                  label={t('statusesCategory')}
                  value={newCategory}
                  onChange={(v) => setNewCategory(v as IdeaStatus)}
                  disabled={pendingKey !== null}
                >
                  {STATUS_CATEGORIES.map((c) => (
                    <option key={c} value={c}>
                      {categoryLabel[c]}
                    </option>
                  ))}
                </Select>
                <TextField
                  className="max-w-ds-field"
                  testId="status-new-label-de"
                  label={t('statusesLabelDe')}
                  value={newDe}
                  onChange={(e) => setNewDe(e.target.value)}
                  maxLength={40}
                  disabled={pendingKey !== null}
                />
                <TextField
                  className="max-w-ds-field"
                  testId="status-new-label-en"
                  label={t('statusesLabelEn')}
                  value={newEn}
                  onChange={(e) => setNewEn(e.target.value)}
                  maxLength={40}
                  disabled={pendingKey !== null}
                />
                <div>
                  <Button
                    testId="status-new-add"
                    type="button"
                    onClick={() => void handleCreate()}
                    disabled={pendingKey !== null || (newDe.trim() === '' && newEn.trim() === '')}
                    loading={pendingKey === '__new'}
                  >
                    {t('statusesAdd')}
                  </Button>
                </div>
              </div>
            )}
            {error !== null && (
              <InlineNotice tone="error" testId="status-settings-error">
                {error}
              </InlineNotice>
            )}
            {saved !== null && (
              <InlineNotice tone="success" testId="status-settings-saved">
                {saved}
              </InlineNotice>
            )}
          </div>
        </>
      )}
    </Section>
  )
}

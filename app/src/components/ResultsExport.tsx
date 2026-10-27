import { Button, InlineNotice, Section, Select } from '@votepit/ui/ds'
import { FileSpreadsheet } from 'lucide-react'
import { useState } from 'react'
import type { ApiError, IdeaStatus, ResultsExportSort } from '../lib/api'
import { downloadBoardResultsCsv } from '../lib/api'
import { useI18n, useT } from '../lib/i18n/context'

const STATUS_FILTERS: readonly IdeaStatus[] = [
  'open',
  'planned',
  'in_progress',
  'done',
  'declined',
  'archived',
]

/**
 * Board settings → results export (broaden-use-cases issue 07). Downloads
 * the board's published ideas with votes, score, consensus, comments, tags
 * and status as CSV — e.g. as an attachment to meeting minutes. The UI
 * language picks the label language and the separator (German → `;`).
 * accountAdmin only — the same gate as the rest of AdminPage.
 */
export function ResultsExport({ boardSlug }: { boardSlug: string }) {
  const t = useT('adminPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const [sort, setSort] = useState<ResultsExportSort>('top')
  const [status, setStatus] = useState<IdeaStatus | ''>('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const statusLabel: Record<IdeaStatus, string> = {
    open: tCommon('status.open'),
    planned: tCommon('status.planned'),
    in_progress: tCommon('status.inProgress'),
    done: tCommon('status.done'),
    declined: tCommon('status.declined'),
    archived: tCommon('status.archived'),
  }

  async function handleDownload() {
    setBusy(true)
    setError(null)
    try {
      await downloadBoardResultsCsv(boardSlug, {
        sort,
        lang: language,
        ...(status ? { status } : {}),
      })
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.status === 429 ? t('exportRateLimited') : t('exportError'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Section
      testId="results-export-section"
      title={t('exportHeading')}
      icon={<FileSpreadsheet size={16} />}
      description={t('exportSubtitle')}
    >
      <div className="flex flex-wrap items-end gap-ds-sm">
        <Select
          testId="results-export-sort"
          label={t('exportSortLabel')}
          value={sort}
          onChange={(v) => setSort(v === 'newest' ? 'newest' : 'top')}
          disabled={busy}
          className="w-48"
        >
          <option value="top">{t('exportSortTop')}</option>
          <option value="newest">{t('exportSortNewest')}</option>
        </Select>
        <Select
          testId="results-export-status"
          label={t('exportStatusLabel')}
          value={status}
          onChange={(v) => setStatus(STATUS_FILTERS.find((s) => s === v) ?? '')}
          disabled={busy}
          className="w-48"
        >
          <option value="">{t('exportStatusAll')}</option>
          {STATUS_FILTERS.map((s) => (
            <option key={s} value={s}>
              {statusLabel[s]}
            </option>
          ))}
        </Select>
        <Button
          type="button"
          testId="results-export-download"
          onClick={() => void handleDownload()}
          loading={busy}
          disabled={busy}
        >
          {t('exportDownload')}
        </Button>
      </div>
      <p className="mt-ds-sm text-vp-sm text-vp-text-muted">{t('exportPrivacyNote')}</p>
      {error !== null && (
        <InlineNotice tone="error" role="alert">
          {error}
        </InlineNotice>
      )}
    </Section>
  )
}

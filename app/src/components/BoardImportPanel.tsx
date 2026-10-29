import { Button, InlineNotice, Section, Select } from '@votepit/ui/ds'
import { Upload } from 'lucide-react'
import { useRef, useState } from 'react'
import type { ApiError, ImportFormat, ImportReport } from '../lib/api'
import { IMPORT_MAX_BYTES, importBoardIdeas } from '../lib/api'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

const REASONS = [
  'too_large',
  'too_many_rows',
  'empty',
  'invalid_encoding',
  'malformed_csv',
  'missing_title_column',
]
const CODES = [
  'title_missing',
  'title_too_long',
  'body_too_long',
  'column_count_mismatch',
  'duplicate_title',
  'status_unknown',
  'created_at_invalid',
  'votes_invalid',
  'tag_too_long',
  'too_many_tags',
  'tag_limit',
]
const STATUS_KEYS: Record<string, string> = {
  open: 'status.open',
  planned: 'status.planned',
  in_progress: 'status.inProgress',
  done: 'status.done',
  declined: 'status.declined',
  archived: 'status.archived',
}

function newImportKey(): string {
  return crypto.randomUUID()
}

type Outcome = { tone: 'success' | 'info' | 'error'; message: string }

/**
 * Board admin: import ideas from a CSV / Canny / Featurebase export.
 * Two steps — "check" runs a server-side dry run and shows the validation
 * report + preview, "import" applies it with an idempotency key bound to
 * the selected file (a double click or retry cannot import twice).
 * Everything from the file is rendered as plain text.
 */
export function BoardImportPanel({ boardSlug }: { boardSlug: string }) {
  const t = useT('boardImport')
  const tCommon = useT('common')
  const { language } = useI18n()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const [format, setFormat] = useState<ImportFormat>('csv')
  const [fileName, setFileName] = useState<string | null>(null)
  const [content, setContent] = useState<string | null>(null)
  const [importKey, setImportKey] = useState(newImportKey)
  const [report, setReport] = useState<ImportReport | null>(null)
  const [busy, setBusy] = useState<'check' | 'import' | null>(null)
  const [outcome, setOutcome] = useState<Outcome | null>(null)

  const reset = () => {
    setReport(null)
    setOutcome(null)
    setImportKey(newImportKey())
  }

  const errorMessage = (err: unknown): string => {
    const apiErr = err as ApiError
    const payload = apiErr?.payload
    if (payload?.key === 'import_invalid' && payload.reason && REASONS.includes(payload.reason)) {
      return t(`reason.${payload.reason}`)
    }
    if (payload?.key === 'import_conflict') return t('conflict')
    return payload?.message ?? t('genericError')
  }

  const handleFile = async (file: File) => {
    reset()
    setFileName(file.name)
    setContent(null)
    if (file.size > IMPORT_MAX_BYTES) {
      setOutcome({ tone: 'error', message: t('fileTooLarge') })
      return
    }
    try {
      setContent(await file.text())
    } catch {
      setOutcome({ tone: 'error', message: t('fileReadError') })
    }
  }

  const run = async (dryRun: boolean) => {
    if (content === null) return
    setBusy(dryRun ? 'check' : 'import')
    setOutcome(null)
    try {
      const res = await importBoardIdeas(boardSlug, {
        format,
        content,
        dryRun,
        idempotencyKey: importKey,
      })
      if (dryRun) {
        setReport(res.report)
      } else {
        setReport(null)
        setOutcome(
          res.report.result === 'replayed'
            ? { tone: 'info', message: t('replayed') }
            : { tone: 'success', message: t('done', { count: res.report.importable }) },
        )
      }
    } catch (err) {
      setOutcome({ tone: 'error', message: errorMessage(err) })
    } finally {
      setBusy(null)
    }
  }

  return (
    <Section
      testId="board-import-section"
      title={t('heading')}
      icon={<Upload size={16} />}
      description={t('intro')}
      flush
    >
      <div className="flex flex-col gap-4 px-ds-md sm:px-ds-lg py-ds-lg">
        <p className="text-vp-sm text-vp-text-muted">{t('privacyNote')}</p>

        <Select
          testId="board-import-format"
          label={t('formatLabel')}
          id="admin-import-format"
          name="import_format"
          value={format}
          onChange={(v) => {
            setFormat(v as ImportFormat)
            reset()
          }}
          hint={format === 'csv' ? t('csvTemplateHint') : undefined}
          disabled={busy !== null}
          className="max-w-ds-field"
        >
          <option value="csv">{t('formatCsv')}</option>
          <option value="canny">{t('formatCanny')}</option>
          <option value="featurebase">{t('formatFeaturebase')}</option>
        </Select>

        <div className="flex flex-wrap items-center gap-3">
          <input
            ref={fileInputRef}
            type="file"
            accept=".csv,text/csv"
            className="sr-only"
            aria-label={t('chooseFile')}
            onChange={(e) => {
              const file = e.target.files?.[0]
              e.target.value = ''
              if (file) void handleFile(file)
            }}
          />
          <Button
            type="button"
            variant="secondary"
            testId="board-import-choose-file"
            onClick={() => fileInputRef.current?.click()}
            disabled={busy !== null}
          >
            {t('chooseFile')}
          </Button>
          <span className="text-vp-sm text-vp-text-muted break-words">
            {fileName ?? t('noFile')}
          </span>
          <Button
            type="button"
            testId="board-import-check"
            onClick={() => void run(true)}
            disabled={content === null || busy !== null}
            loading={busy === 'check'}
          >
            {busy === 'check' ? t('checking') : t('check')}
          </Button>
        </div>

        {outcome !== null && <InlineNotice tone={outcome.tone}>{outcome.message}</InlineNotice>}

        {report !== null && (
          <div className="flex flex-col gap-3">
            <p className="text-vp-sm">
              {t('summary', {
                total: report.total_rows,
                importable: report.importable,
                skipped: report.skipped,
              })}
            </p>
            {report.new_tags.length > 0 && (
              <p className="text-vp-sm text-vp-text-muted">
                {t('newTags', { tags: report.new_tags.join(', ') })}
              </p>
            )}
            {report.ignored_columns.length > 0 && (
              <p className="text-vp-sm text-vp-text-muted">
                {t('ignoredColumns', { columns: report.ignored_columns.join(', ') })}
              </p>
            )}

            {report.issues.length > 0 && (
              <div>
                <h4 className="text-vp-sm font-medium mb-1">{t('issuesHeading')}</h4>
                <ul className="text-vp-sm flex flex-col gap-0.5 max-h-48 overflow-y-auto">
                  {report.issues.map((issue) => (
                    <li
                      key={`${issue.line}-${issue.field}-${issue.code}`}
                      className={
                        issue.severity === 'error'
                          ? 'text-vp-vote-down-strong'
                          : 'text-vp-text-muted'
                      }
                    >
                      {t('issueLine', { line: issue.line })}:{' '}
                      {CODES.includes(issue.code) ? t(`code.${issue.code}`) : issue.code}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {report.preview.length > 0 && (
              <div className="overflow-x-auto">
                <h4 className="text-vp-sm font-medium mb-1">{t('previewHeading')}</h4>
                <table className="text-vp-sm w-full">
                  <thead>
                    <tr className="text-left text-vp-text-muted">
                      <th className="pr-3 font-normal">{t('previewTitle')}</th>
                      <th className="pr-3 font-normal">{t('previewStatus')}</th>
                      <th className="pr-3 font-normal text-right">{t('previewVotes')}</th>
                      <th className="font-normal">{t('previewDate')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.preview.map((row) => (
                      <tr key={row.line}>
                        <td className="pr-3 py-0.5 break-words">{row.title}</td>
                        <td className="pr-3 py-0.5 break-words">
                          {STATUS_KEYS[row.status] ? tCommon(STATUS_KEYS[row.status]) : row.status}
                        </td>
                        <td className="pr-3 py-0.5 text-right tabular-nums">{row.votes}</td>
                        <td className="py-0.5 break-words">
                          {formatDate(row.created_at, language)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {report.importable > 0 ? (
              <div>
                <Button
                  type="button"
                  testId="board-import-confirm"
                  onClick={() => void run(false)}
                  disabled={busy !== null}
                  loading={busy === 'import'}
                >
                  {busy === 'import' ? t('importing') : t('confirm', { count: report.importable })}
                </Button>
              </div>
            ) : (
              <InlineNotice tone="info">{t('nothingToImport')}</InlineNotice>
            )}
          </div>
        )}
      </div>
    </Section>
  )
}

/**
 * Board import (CSV, Canny and Featurebase exports) —
 * POST /admin/boards/{slug}/import. The file is read in the browser and sent
 * as text; the server parses it strictly, answers a dry run with a
 * validation report and only writes on an explicit `dry_run: false`.
 */

import { accountPath } from '../accountContext'
import { request } from './client'

export type ImportFormat = 'csv' | 'canny' | 'featurebase'

export interface ImportIssue {
  line: number
  field: string
  code: string
  severity: 'error' | 'warning'
}

export interface ImportPreviewRow {
  line: number
  title: string
  status: string
  votes: number
  created_at: string
  tags: string[]
}

export interface ImportReport {
  /** 'ok' (dry run or applied) | 'replayed' (same key + file already imported). */
  result: 'ok' | 'replayed'
  total_rows: number
  importable: number
  skipped: number
  new_tags: string[]
  ignored_columns: string[]
  issues: ImportIssue[]
  preview: ImportPreviewRow[]
  import_id: number | null
}

export interface ImportResponse {
  ok: boolean
  dry_run: boolean
  report: ImportReport
}

/** Hard limit enforced by the server (CsvTable::MAX_BYTES) — checked client-side first to fail fast. */
export const IMPORT_MAX_BYTES = 2 * 1024 * 1024

export async function importBoardIdeas(
  slug: string,
  data: { format: ImportFormat; content: string; dryRun: boolean; idempotencyKey: string },
): Promise<ImportResponse> {
  return request<ImportResponse>('POST', accountPath(`/admin/boards/${slug}/import`), {
    format: data.format,
    content: data.content,
    dry_run: data.dryRun,
    idempotency_key: data.idempotencyKey,
  })
}

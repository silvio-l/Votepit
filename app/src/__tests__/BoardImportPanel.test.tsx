/**
 * RTL tests for BoardImportPanel — the two-step import flow: a file is first
 * checked (dry run, report shown) and only imported on explicit
 * confirmation, with the same idempotency key for both requests.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { BoardImportPanel } from '../components/BoardImportPanel'
import type { ImportReport } from '../lib/api'
import * as api from '../lib/api'

vi.mock('../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../lib/api')>('../lib/api')
  return { ...actual, importBoardIdeas: vi.fn() }
})

const report: ImportReport = {
  result: 'ok',
  total_rows: 3,
  importable: 2,
  skipped: 1,
  new_tags: ['UI'],
  ignored_columns: ['author email'],
  issues: [{ line: 4, field: 'title', code: 'title_missing', severity: 'error' }],
  preview: [
    {
      line: 2,
      title: '<b>Dark mode</b>',
      status: 'planned',
      votes: 42,
      created_at: '2024-03-05 10:20:30',
      tags: ['UI'],
    },
  ],
  import_id: null,
}

async function selectFile(user: ReturnType<typeof userEvent.setup>) {
  const input = screen.getByLabelText('Datei auswählen', { selector: 'input' })
  await user.upload(input, new File(['title\nDark mode\n'], 'ideas.csv', { type: 'text/csv' }))
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('BoardImportPanel', () => {
  it('checks the file first and imports only after confirmation, with one idempotency key', async () => {
    vi.mocked(api.importBoardIdeas)
      .mockResolvedValueOnce({ ok: true, dry_run: true, report })
      .mockResolvedValueOnce({ ok: true, dry_run: false, report: { ...report, import_id: 7 } })
    const user = userEvent.setup()
    render(<BoardImportPanel boardSlug="demo" />)

    await selectFile(user)
    await user.click(screen.getByRole('button', { name: 'Datei prüfen' }))

    expect(await screen.findByText(/3 Zeilen gelesen/)).toBeInTheDocument()
    expect(screen.getByText(/Zeile 4/)).toBeInTheDocument()
    // File content is shown as text, never as markup.
    expect(screen.getByText('<b>Dark mode</b>')).toBeInTheDocument()

    const first = vi.mocked(api.importBoardIdeas).mock.calls[0]
    expect(first[1]).toMatchObject({ format: 'csv', dryRun: true, content: 'title\nDark mode\n' })

    await user.click(screen.getByRole('button', { name: '2 Ideen importieren' }))
    await waitFor(() => expect(screen.getByText('2 Ideen wurden importiert.')).toBeInTheDocument())

    const second = vi.mocked(api.importBoardIdeas).mock.calls[1]
    expect(second[1]).toMatchObject({ dryRun: false })
    expect(second[1].idempotencyKey).toBe(first[1].idempotencyKey)
  })

  it('explains a rejected file with its reason', async () => {
    vi.mocked(api.importBoardIdeas).mockRejectedValueOnce(
      new api.ApiError(422, {
        key: 'import_invalid',
        reason: 'invalid_encoding',
        message: 'The file cannot be imported.',
      }),
    )
    const user = userEvent.setup()
    render(<BoardImportPanel boardSlug="demo" />)

    await selectFile(user)
    await user.click(screen.getByRole('button', { name: 'Datei prüfen' }))

    expect(await screen.findByText(/nicht UTF-8-kodiert/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Ideen importieren/ })).not.toBeInTheDocument()
  })
})

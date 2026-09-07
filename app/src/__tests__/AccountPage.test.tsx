/**
 * RTL tests for AccountPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made. Covers the
 * owner's account summary, the GDPR data export (JSON/CSV download flow),
 * the self-service deletion request with typed confirmation, the undo of a
 * pending deletion, and the access gates (403 → no access, anon → login).
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AccountPage from '../pages/AccountPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false, memberships: [{ account_slug: 'acme', role: 'owner' }] },
}

const ACCOUNT_RESPONSE = {
  account_id: 42,
  slug: 'acme',
  name: 'Acme Inc.',
  is_default_account: false,
  deletion_scheduled_at: null,
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderAccountPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/account']}>
      <Routes>
        <Route path="/admin/account" element={<AccountPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('AccountPage — summary', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}))
    renderAccountPage()
    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  it('shows name and slug of the account', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: ACCOUNT_RESPONSE }])

    renderAccountPage()

    await waitFor(() => expect(screen.getByDisplayValue('Acme Inc.')).toBeInTheDocument())
    expect(screen.getByDisplayValue('acme')).toBeInTheDocument()
    expect(screen.queryByText('Account wird gelöscht')).not.toBeInTheDocument()
  })

  it('links the admin section nav to Boards, Members and Account — nothing plan-related', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: ACCOUNT_RESPONSE }])

    renderAccountPage()

    await waitFor(() => expect(screen.getByDisplayValue('Acme Inc.')).toBeInTheDocument())
    const hrefs = screen.getAllByRole('link').map((l) => l.getAttribute('href'))
    expect(hrefs).toContain('/admin/boards')
    expect(hrefs).toContain('/admin/members')
    expect(hrefs).toContain('/admin/account')
    expect(hrefs).not.toContain('/admin/billing')
  })
})

describe('AccountPage — pending deletion', () => {
  it('shows the deletion-scheduled notice with the remaining days', async () => {
    const deadline = new Date(Date.now() + 5 * 86_400_000).toISOString()
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { ...ACCOUNT_RESPONSE, deletion_scheduled_at: deadline } },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Account wird gelöscht')).toBeInTheDocument())
    expect(screen.getByText(/Tage verbleibend/)).toBeInTheDocument()
  })

  it('shows hours-remaining text and an undo button under 72h', async () => {
    const deadline = new Date(Date.now() + 5 * 3_600_000).toISOString()
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { ...ACCOUNT_RESPONSE, deletion_scheduled_at: deadline } },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Account wird gelöscht')).toBeInTheDocument())
    expect(screen.getByText(/Stunden verbleibend/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Löschung abbrechen' })).toBeInTheDocument()
  })

  it('undo deletion: clears the notice on success', async () => {
    const deadline = new Date(Date.now() + 5 * 86_400_000).toISOString()
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { ...ACCOUNT_RESPONSE, deletion_scheduled_at: deadline } },
      { body: { ok: true } },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Account wird gelöscht')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'Löschung abbrechen' }))

    await waitFor(() => expect(screen.queryByText('Account wird gelöscht')).not.toBeInTheDocument())
  })

  it('undo deletion: shows an error message on failure and keeps the notice', async () => {
    const deadline = new Date(Date.now() + 5 * 86_400_000).toISOString()
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { ...ACCOUNT_RESPONSE, deletion_scheduled_at: deadline } },
      { body: { error: { key: 'not_found', message: 'Account nicht gefunden.' } }, status: 404 },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Account wird gelöscht')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'Löschung abbrechen' }))

    await waitFor(() => expect(screen.getByText('Account nicht gefunden.')).toBeInTheDocument())
    expect(screen.getByText('Account wird gelöscht')).toBeInTheDocument()
  })
})

describe('AccountPage — self-service deletion', () => {
  it('hides the danger zone entirely for the self-host default account', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { ...ACCOUNT_RESPONSE, is_default_account: true } },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByDisplayValue('Acme Inc.')).toBeInTheDocument())
    expect(screen.queryByText('Gefahrenzone')).not.toBeInTheDocument()
  })

  it('reveals a typed-confirmation input, disables submit until the slug matches exactly, and succeeds', async () => {
    const deadline = new Date(Date.now() + 2 * 86_400_000).toISOString()
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: ACCOUNT_RESPONSE },
      { body: { ok: true, deletion_scheduled_at: deadline } },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Gefahrenzone')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'Account löschen' }))

    const submitButton = screen.getByRole('button', { name: 'Endgültig löschen' })
    expect(submitButton).toBeDisabled()

    const input = screen.getByPlaceholderText('Account-Slug')
    fireEvent.change(input, { target: { value: 'wrong-slug' } })
    expect(submitButton).toBeDisabled()

    fireEvent.change(input, { target: { value: 'acme' } })
    expect(submitButton).not.toBeDisabled()

    fireEvent.click(submitButton)

    await waitFor(() => expect(screen.getByText('Account wird gelöscht')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Endgültig löschen' })).not.toBeInTheDocument()
  })

  it('shows an error message when the request fails', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: ACCOUNT_RESPONSE },
      {
        body: {
          error: { key: 'confirmation_mismatch', message: 'Bestätigung stimmt nicht überein.' },
        },
        status: 422,
      },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Gefahrenzone')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'Account löschen' }))
    fireEvent.change(screen.getByPlaceholderText('Account-Slug'), { target: { value: 'acme' } })
    fireEvent.click(screen.getByRole('button', { name: 'Endgültig löschen' }))

    await waitFor(() =>
      expect(screen.getByText('Bestätigung stimmt nicht überein.')).toBeInTheDocument(),
    )
  })

  it('the confirm-input flow can be dismissed via "Abbrechen"', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: ACCOUNT_RESPONSE }])

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Gefahrenzone')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'Account löschen' }))
    expect(screen.getByPlaceholderText('Account-Slug')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Abbrechen' }))
    expect(screen.queryByPlaceholderText('Account-Slug')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Account löschen' })).toBeInTheDocument()
  })

  it('the "Account löschen" button is disabled once a deletion is already scheduled', async () => {
    const deadline = new Date(Date.now() + 2 * 86_400_000).toISOString()
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { ...ACCOUNT_RESPONSE, deletion_scheduled_at: deadline } },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Gefahrenzone')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Account löschen' })).toBeDisabled()
  })
})

describe('AccountPage — access', () => {
  it('shows no-access message when GET /admin/account returns 403', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { error: { key: 'forbidden', message: 'Forbidden' } }, status: 403 },
    ])

    renderAccountPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Kein Zugriff')).toBeInTheDocument()
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderAccountPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('AccountPage — customer self-export', () => {
  /**
   * Mocks fetch so bootstrap/getAccountSettings get their usual JSON
   * responses, but any request to /admin/export is routed separately —
   * either a successful file response (Content-Disposition + body) or an
   * error JSON payload with the given status.
   */
  function makeFetchMockWithExport(
    exportOutcome: { status: number; filename?: string } = {
      status: 200,
      filename: 'votepit-export-acme-20260101.json',
    },
  ) {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      if (url.includes('/admin/export')) {
        if (exportOutcome.status >= 400) {
          return new Response(
            JSON.stringify({ error: { key: 'export_failed', message: 'Export fehlgeschlagen.' } }),
            { status: exportOutcome.status, headers: { 'Content-Type': 'application/json' } },
          )
        }
        // String body, not `new Blob(...)`: jsdom's Blob lacks `.stream()`, which Node 22's native Response requires and throws on (Node 26 doesn't).
        return new Response('{"exported_at":"now"}', {
          status: 200,
          headers: {
            'Content-Type': 'application/json',
            'Content-Disposition': `attachment; filename="${exportOutcome.filename}"`,
          },
        })
      }
      if (url.endsWith('/api/bootstrap')) {
        return new Response(JSON.stringify(BOOTSTRAP_OK), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      return new Response(JSON.stringify(ACCOUNT_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })
  }

  beforeEach(() => {
    // jsdom does not implement the Blob-URL object API — stub it so the
    // download flow (URL.createObjectURL → <a download> click → revoke)
    // doesn't throw "not implemented".
    URL.createObjectURL = vi.fn(() => 'blob:mock-url')
    URL.revokeObjectURL = vi.fn()
  })

  it('shows an "export my data" section with JSON and CSV buttons', async () => {
    makeFetchMockWithExport()

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Meine Daten exportieren')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: /Als JSON exportieren/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Als CSV \(ZIP\) exportieren/i })).toBeInTheDocument()
  })

  it('downloads a JSON export when clicking the JSON button', async () => {
    makeFetchMockWithExport({ status: 200, filename: 'votepit-export-acme-20260101.json' })

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Meine Daten exportieren')).toBeInTheDocument())
    screen.getByRole('button', { name: /Als JSON exportieren/i }).click()

    await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalled())
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock-url')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('downloads a CSV export when clicking the CSV button', async () => {
    makeFetchMockWithExport({ status: 200, filename: 'votepit-export-acme-20260101.zip' })

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Meine Daten exportieren')).toBeInTheDocument())
    screen.getByRole('button', { name: /Als CSV \(ZIP\) exportieren/i }).click()

    await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalled())
  })

  it('shows an error message when the export request fails', async () => {
    makeFetchMockWithExport({ status: 500 })

    renderAccountPage()

    await waitFor(() => expect(screen.getByText('Meine Daten exportieren')).toBeInTheDocument())
    screen.getByRole('button', { name: /Als JSON exportieren/i }).click()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Export fehlgeschlagen.')).toBeInTheDocument()
  })
})

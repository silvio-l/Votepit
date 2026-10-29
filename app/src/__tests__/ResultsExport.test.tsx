/**
 * ResultsExport (broaden-use-cases issue 07) — board settings section that
 * downloads the board's results as CSV with the chosen sort/status filter
 * and the UI language (German → German labels + `;` on the server).
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ResultsExport } from '../components/ResultsExport'

function mockFetch(status = 200) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    if (status >= 400) {
      return new Response(JSON.stringify({ error: { key: 'x', message: 'x' } }), {
        status,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    return new Response('﻿id;title\r\n', {
      status: 200,
      headers: {
        'Content-Type': 'text/csv; charset=utf-8',
        'Content-Disposition': 'attachment; filename="votepit-results-demo-20261001-120000.csv"',
      },
    })
  })
}

beforeEach(() => {
  URL.createObjectURL = vi.fn(() => 'blob:mock-url')
  URL.revokeObjectURL = vi.fn()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('ResultsExport', () => {
  it('downloads the CSV sorted by score with the UI language by default', async () => {
    const fetchSpy = mockFetch()
    render(<ResultsExport boardSlug="demo" />)

    await userEvent.click(screen.getByRole('button', { name: 'CSV herunterladen' }))

    await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1))
    const url = String(fetchSpy.mock.calls[0]?.[0])
    expect(url).toContain('/admin/boards/demo/export?')
    expect(url).toContain('sort=top')
    expect(url).toContain('lang=de')
    expect(url).not.toContain('status=')
    expect(URL.createObjectURL).toHaveBeenCalled()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('passes the chosen sort and status filter', async () => {
    const fetchSpy = mockFetch()
    render(<ResultsExport boardSlug="demo" />)

    await userEvent.selectOptions(screen.getByLabelText('Sortierung'), 'newest')
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'done')
    await userEvent.click(screen.getByRole('button', { name: 'CSV herunterladen' }))

    await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1))
    const url = String(fetchSpy.mock.calls[0]?.[0])
    expect(url).toContain('sort=newest')
    expect(url).toContain('status=done')
  })

  it('tells the admin that no author identities are exported', () => {
    render(<ResultsExport boardSlug="demo" />)
    expect(screen.getByText(/keine Namen oder E-Mail-Adressen/)).toBeInTheDocument()
  })

  it('shows a rate-limit message on 429 and a generic one otherwise', async () => {
    mockFetch(429)
    const { unmount } = render(<ResultsExport boardSlug="demo" />)
    await userEvent.click(screen.getByRole('button', { name: 'CSV herunterladen' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Zu viele Exporte')
    unmount()
    vi.restoreAllMocks()

    mockFetch(500)
    render(<ResultsExport boardSlug="demo" />)
    await userEvent.click(screen.getByRole('button', { name: 'CSV herunterladen' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Der Export ist fehlgeschlagen')
  })
})

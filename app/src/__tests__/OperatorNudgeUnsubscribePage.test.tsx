/**
 * RTL tests for OperatorNudgeUnsubscribePage — the one-click unsubscribe of
 * the operator nudge mails. fetch is mocked globally.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OperatorNudgeUnsubscribePage from '../pages/OperatorNudgeUnsubscribePage'

function renderPage(token?: string) {
  const search = token ? `?token=${encodeURIComponent(token)}` : ''
  return render(
    <MemoryRouter initialEntries={[`/operator-nudges/unsubscribe${search}`]}>
      <Routes>
        <Route path="/operator-nudges/unsubscribe" element={<OperatorNudgeUnsubscribePage />} />
      </Routes>
    </MemoryRouter>,
  )
}

function jsonResponse(body: object, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('OperatorNudgeUnsubscribePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('does not POST on load: a mail scanner GET must not switch anything off', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(jsonResponse({ ok: true, kind: 'onboarding' }))

    renderPage('MQ.abc')

    expect(await screen.findByTestId('operator-nudge-unsubscribe-confirm')).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('posts the token only after the button click and confirms', async () => {
    const user = userEvent.setup()
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(jsonResponse({ ok: true, kind: 'onboarding' }))

    renderPage('MQ.abc')

    await user.click(await screen.findByTestId('operator-nudge-unsubscribe-confirm'))

    expect(await screen.findByRole('heading', { name: 'Hinweis abbestellt' })).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/operator-nudges/unsubscribe')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body as string)).toEqual({ token: 'MQ.abc' })
  })

  it('shows the invalid-link message when the server rejects the token, without a retry', async () => {
    const user = userEvent.setup()
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse({ error: { key: 'invalid_token', message: 'The link is invalid.' } }, 400),
    )

    renderPage('forged.token')

    await user.click(await screen.findByTestId('operator-nudge-unsubscribe-confirm'))

    expect(await screen.findByText('Der Abmeldelink ist ungültig.')).toBeInTheDocument()
    expect(screen.queryByTestId('operator-nudge-unsubscribe-retry')).not.toBeInTheDocument()
  })

  it('offers a retry after a network error and completes on retry', async () => {
    const user = userEvent.setup()
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockRejectedValueOnce(new TypeError('Failed to fetch'))
      .mockResolvedValueOnce(jsonResponse({ ok: true, kind: 'onboarding' }))

    renderPage('MQ.abc')

    await user.click(await screen.findByTestId('operator-nudge-unsubscribe-confirm'))
    await user.click(await screen.findByTestId('operator-nudge-unsubscribe-retry'))

    expect(await screen.findByRole('heading', { name: 'Hinweis abbestellt' })).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('never calls the server without a token', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')

    renderPage()

    expect(await screen.findByText('Der Abmeldelink ist ungültig.')).toBeInTheDocument()
    expect(screen.queryByTestId('operator-nudge-unsubscribe-confirm')).not.toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

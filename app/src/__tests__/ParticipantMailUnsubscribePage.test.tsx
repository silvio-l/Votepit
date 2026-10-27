/**
 * RTL tests for ParticipantMailUnsubscribePage — the one-click unsubscribe of
 * the participant mails. fetch is mocked globally.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ParticipantMailUnsubscribePage from '../pages/ParticipantMailUnsubscribePage'

function renderPage(token?: string) {
  const search = token ? `?token=${encodeURIComponent(token)}` : ''
  return render(
    <MemoryRouter initialEntries={[`/mail/unsubscribe${search}`]}>
      <Routes>
        <Route path="/mail/unsubscribe" element={<ParticipantMailUnsubscribePage />} />
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

describe('ParticipantMailUnsubscribePage', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('does not POST on load: a mail scanner GET must not unsubscribe anybody', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ ok: true }))

    renderPage('abc.def')

    expect(await screen.findByTestId('participant-mail-unsubscribe-confirm')).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('posts the token only after the button click and confirms', async () => {
    const user = userEvent.setup()
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ ok: true }))

    renderPage('abc.def')

    await user.click(await screen.findByTestId('participant-mail-unsubscribe-confirm'))

    expect(await screen.findByRole('heading', { name: 'Abbestellt' })).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/participant-mail/unsubscribe')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body as string)).toEqual({ token: 'abc.def' })
  })

  it('shows the invalid-link message when the server rejects the token, without a retry', async () => {
    const user = userEvent.setup()
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse({ error: { key: 'invalid_token', message: 'The link is invalid.' } }, 400),
    )

    renderPage('forged.token')

    await user.click(await screen.findByTestId('participant-mail-unsubscribe-confirm'))

    expect(await screen.findByText('Der Abmeldelink ist ungültig.')).toBeInTheDocument()
    expect(screen.queryByTestId('participant-mail-unsubscribe-retry')).not.toBeInTheDocument()
  })

  it('offers a retry after a transient failure', async () => {
    const user = userEvent.setup()
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse({ error: { key: 'http_error', message: 'x' } }, 500))
      .mockResolvedValueOnce(jsonResponse({ ok: true }))

    renderPage('abc.def')

    await user.click(await screen.findByTestId('participant-mail-unsubscribe-confirm'))
    await user.click(await screen.findByTestId('participant-mail-unsubscribe-retry'))

    expect(await screen.findByRole('heading', { name: 'Abbestellt' })).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('never calls the server without a token', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')

    renderPage()

    expect(await screen.findByText('Der Abmeldelink ist ungültig.')).toBeInTheDocument()
    expect(screen.queryByTestId('participant-mail-unsubscribe-confirm')).not.toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

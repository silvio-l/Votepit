/**
 * RTL tests for ParticipantMailSection — the profile's cadence switches for the
 * participant mails. fetch is mocked globally; no real network calls are made.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ParticipantMailSection } from '../components/ParticipantMailSection'

const PREFS = {
  categories: {
    my_ideas: { cadence: 'instant', allowed: ['instant', 'daily', 'weekly', 'off'] },
    participated: { cadence: 'daily', allowed: ['instant', 'daily', 'weekly', 'off'] },
    new_ideas: { cadence: 'off', allowed: ['weekly', 'off'] },
  },
}

function jsonResponse(body: object, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('ParticipantMailSection', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('loads the saved cadences into the controls', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(PREFS))

    render(<ParticipantMailSection />)

    const myIdeas = await screen.findByTestId('profile-participant-mail-my-ideas')
    expect(myIdeas).toHaveValue('instant')
    expect(screen.getByTestId('profile-participant-mail-participated')).toHaveValue('daily')
    expect(screen.getByTestId('profile-participant-mail-new-ideas')).toHaveAttribute(
      'aria-checked',
      'false',
    )
    expect(screen.getByTestId('profile-participant-mail-section')).toBeInTheDocument()
  })

  it('offers only the cadences the server allows per category', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(PREFS))

    render(<ParticipantMailSection />)

    const myIdeas = await screen.findByTestId('profile-participant-mail-my-ideas')
    expect(myIdeas.querySelectorAll('option')).toHaveLength(4)
    expect(screen.getByRole('switch', { name: /Neue Ideen/ })).toBeInTheDocument()
  })

  it('keeps save disabled until something changed', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(PREFS))

    render(<ParticipantMailSection />)

    await screen.findByTestId('profile-participant-mail-my-ideas')
    expect(screen.getByTestId('profile-participant-mail-save')).toBeDisabled()
  })

  it('saves only the changed categories and shows the confirmation', async () => {
    const user = userEvent.setup()
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse(PREFS))
      .mockResolvedValueOnce(
        jsonResponse({
          categories: {
            ...PREFS.categories,
            participated: { cadence: 'weekly', allowed: PREFS.categories.participated.allowed },
          },
        }),
      )

    render(<ParticipantMailSection />)

    const participated = await screen.findByTestId('profile-participant-mail-participated')
    await user.selectOptions(participated, 'weekly')
    await user.click(screen.getByTestId('profile-participant-mail-save'))

    await waitFor(() => expect(screen.getByText('Gespeichert.')).toBeInTheDocument())
    const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit]
    expect(url).toBe('/account/participant-mail-preferences')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(String(init.body))).toEqual({ participated: 'weekly' })
  })

  it('turns the new-ideas digest on only as an explicit weekly opt-in', async () => {
    const user = userEvent.setup()
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse(PREFS))
      .mockResolvedValueOnce(
        jsonResponse({
          categories: {
            ...PREFS.categories,
            new_ideas: { cadence: 'weekly', allowed: ['weekly', 'off'] },
          },
        }),
      )

    render(<ParticipantMailSection />)

    await user.click(await screen.findByTestId('profile-participant-mail-new-ideas'))
    await user.click(screen.getByTestId('profile-participant-mail-save'))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    const [, init] = fetchMock.mock.calls[1] as [string, RequestInit]
    expect(JSON.parse(String(init.body))).toEqual({ new_ideas: 'weekly' })
  })

  it('shows an error when the settings cannot be loaded', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse({ error: { key: 'http_error', message: 'boom' } }, 500),
    )

    render(<ParticipantMailSection />)

    expect(
      await screen.findByText('Deine Mail-Einstellungen konnten nicht geladen werden.'),
    ).toBeInTheDocument()
  })

  it('shows an error and keeps the draft when saving fails', async () => {
    const user = userEvent.setup()
    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse(PREFS))
      .mockResolvedValueOnce(
        jsonResponse({ error: { key: 'invalid_cadence', message: 'no' } }, 400),
      )

    render(<ParticipantMailSection />)

    const myIdeas = await screen.findByTestId('profile-participant-mail-my-ideas')
    await user.selectOptions(myIdeas, 'off')
    await user.click(screen.getByTestId('profile-participant-mail-save'))

    expect(
      await screen.findByText(
        'Die Einstellungen konnten nicht gespeichert werden. Bitte versuche es erneut.',
      ),
    ).toBeInTheDocument()
    expect(myIdeas).toHaveValue('off')
    expect(screen.getByTestId('profile-participant-mail-save')).toBeEnabled()
  })
})

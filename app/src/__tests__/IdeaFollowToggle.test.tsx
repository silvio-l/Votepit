/**
 * RTL tests for IdeaFollowToggle — the follow switch on the idea detail page.
 * fetch is mocked globally; no real network calls are made.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { IdeaFollowToggle } from '../components/IdeaFollowToggle'

function jsonResponse(body: object, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('IdeaFollowToggle', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('reflects the follow state from GET (not following)', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(jsonResponse({ following: false }))

    render(<IdeaFollowToggle boardSlug="demo" ideaId={42} />)

    const toggle = await screen.findByTestId('idea-follow-toggle')
    expect(toggle).toHaveAttribute('aria-pressed', 'false')
    expect(toggle).toHaveTextContent('Verfolgen')
    const [url] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/demo/ideas/42/follow')
  })

  it('reflects an existing follow from GET', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ following: true }))

    render(<IdeaFollowToggle boardSlug="demo" ideaId={42} />)

    const toggle = await screen.findByTestId('idea-follow-toggle')
    expect(toggle).toHaveAttribute('aria-pressed', 'true')
    expect(toggle).toHaveTextContent('Wird verfolgt')
  })

  it('follows on click with POST and shows the new state', async () => {
    const user = userEvent.setup()
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse({ following: false }))
      .mockResolvedValueOnce(jsonResponse({ following: true }))

    render(<IdeaFollowToggle boardSlug="demo" ideaId={42} />)

    await user.click(await screen.findByTestId('idea-follow-toggle'))

    await waitFor(() =>
      expect(screen.getByTestId('idea-follow-toggle')).toHaveAttribute('aria-pressed', 'true'),
    )
    const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit]
    expect(url).toBe('/demo/ideas/42/follow')
    expect(init.method).toBe('POST')
  })

  it('unfollows on click with DELETE', async () => {
    const user = userEvent.setup()
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse({ following: true }))
      .mockResolvedValueOnce(jsonResponse({ following: false }))

    render(<IdeaFollowToggle boardSlug="demo" ideaId={42} />)

    await user.click(await screen.findByTestId('idea-follow-toggle'))

    await waitFor(() =>
      expect(screen.getByTestId('idea-follow-toggle')).toHaveAttribute('aria-pressed', 'false'),
    )
    const [, init] = fetchMock.mock.calls[1] as [string, RequestInit]
    expect(init.method).toBe('DELETE')
  })

  it('shows an error and keeps the previous state when the call fails', async () => {
    const user = userEvent.setup()
    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse({ following: false }))
      .mockResolvedValueOnce(jsonResponse({ error: { key: 'x', message: 'x' } }, 500))

    render(<IdeaFollowToggle boardSlug="demo" ideaId={42} />)

    await user.click(await screen.findByTestId('idea-follow-toggle'))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Das hat nicht geklappt. Bitte versuche es erneut.',
    )
    expect(screen.getByTestId('idea-follow-toggle')).toHaveAttribute('aria-pressed', 'false')
  })

  it('renders nothing when the state cannot be read', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse({ error: { key: 'unauthorized', message: 'no' } }, 401),
    )

    const { container } = render(<IdeaFollowToggle boardSlug="demo" ideaId={42} />)

    await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled())
    await waitFor(() => expect(container).toBeEmptyDOMElement())
  })
})

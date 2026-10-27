/**
 * RTL tests for the idea detail issue-link panel (issue 19): hidden without
 * connection, create button per connected provider, link display and the
 * duplicate error.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { IdeaIssueLinksPanel } from './IdeaIssueLinksPanel'

type Handler = (url: string, init?: RequestInit) => { body: unknown; status?: number }

function mockFetch(handler: Handler) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const { body, status = 200 } = handler(String(input), init)
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

const LINK = {
  provider: 'github',
  external_key: '#7',
  url: 'https://github.com/acme/roadmap/issues/7',
  created_at: '2026-10-01 10:00:00',
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('IdeaIssueLinksPanel', () => {
  it('renders nothing when no tracker is connected and nothing is linked', async () => {
    const fetchSpy = mockFetch(() => ({ body: { allowed: true, providers: [], links: [] } }))
    const { container } = render(<IdeaIssueLinksPanel boardSlug="demo" ideaId={3} />)

    await waitFor(() => expect(fetchSpy).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

  it('creates a GitHub issue and shows the link', async () => {
    let created = false
    const fetchSpy = mockFetch((_url, init) => {
      if (init?.method === 'POST') {
        created = true
        return { status: 201, body: { ok: true, link: LINK } }
      }
      return { body: { allowed: true, providers: ['github'], links: created ? [LINK] : [] } }
    })
    const user = userEvent.setup()

    render(<IdeaIssueLinksPanel boardSlug="demo" ideaId={3} />)
    await user.click(await screen.findByRole('button', { name: 'Issue in GitHub anlegen' }))

    const link = await screen.findByRole('link', { name: /GitHub #7/ })
    expect(link).toHaveAttribute('href', LINK.url)
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    expect(
      screen.queryByRole('button', { name: 'Issue in GitHub anlegen' }),
    ).not.toBeInTheDocument()
    const post = fetchSpy.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(String(post?.[0])).toContain('/admin/boards/demo/ideas/3/issue-links/github')
  })

  it('shows the duplicate error from the server', async () => {
    mockFetch((_url, init) => {
      if (init?.method === 'POST') {
        return { status: 409, body: { error: { key: 'issue_link_exists', message: 'x' } } }
      }
      return { body: { allowed: true, providers: ['linear'], links: [] } }
    })
    const user = userEvent.setup()

    render(<IdeaIssueLinksPanel boardSlug="demo" ideaId={3} />)
    await user.click(await screen.findByRole('button', { name: 'Issue in Linear anlegen' }))

    expect(await screen.findByText('Für diese Idee gibt es schon ein Issue.')).toBeInTheDocument()
  })

  it('hides the create button when the plan does not allow it but keeps existing links', async () => {
    mockFetch(() => ({ body: { allowed: false, providers: ['github', 'linear'], links: [LINK] } }))

    render(<IdeaIssueLinksPanel boardSlug="demo" ideaId={3} />)

    expect(await screen.findByRole('link', { name: /GitHub #7/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /anlegen/ })).not.toBeInTheDocument()
  })
})

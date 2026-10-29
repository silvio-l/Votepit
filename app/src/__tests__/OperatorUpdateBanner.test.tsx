/**
 * RTL tests for OperatorUpdateBanner — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Banner shown when update_available is true (testids, links, hrefs)
 *  2. Banner hidden when update_available is false
 *  3. Banner hidden (no error UI) when the status request rejects
 */

import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { OperatorUpdateBanner } from '../components/OperatorUpdateBanner'

const UPDATE_AVAILABLE_RESPONSE = {
  installed_version: '0.1.7',
  latest_version: '0.1.8',
  update_available: true,
  release_url: 'https://github.com/silvio-l/Votepit/releases/tag/v0.1.8',
  docs_url:
    'https://github.com/silvio-l/Votepit/blob/main/documentation/installation.md#upgrading-an-existing-installation',
  checked_at: '2026-10-28 10:00:00',
  update_check_enabled: true,
}

const UP_TO_DATE_RESPONSE = {
  ...UPDATE_AVAILABLE_RESPONSE,
  latest_version: '0.1.7',
  update_available: false,
  release_url: null,
  docs_url: null,
}

function mockUpdateFetch(response: () => Promise<Response>) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    if (typeof input === 'string' && input.startsWith('/operator/update')) {
      return response()
    }
    throw new Error(`Unexpected fetch: ${String(input)}`)
  })
}

function jsonResponse(body: object, status = 200) {
  return async () =>
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('OperatorUpdateBanner', () => {
  it('shows the banner with version info and both external links when an update is available', async () => {
    mockUpdateFetch(jsonResponse(UPDATE_AVAILABLE_RESPONSE))

    render(<OperatorUpdateBanner />)

    const banner = await screen.findByTestId('operator-update-banner')
    expect(banner).toHaveAttribute('role', 'status')
    expect(banner).toHaveTextContent('0.1.8')
    expect(banner).toHaveTextContent('0.1.7')

    const releaseLink = screen.getByTestId('operator-update-release-link')
    expect(releaseLink).toHaveAttribute(
      'href',
      'https://github.com/silvio-l/Votepit/releases/tag/v0.1.8',
    )
    expect(releaseLink).toHaveAttribute('target', '_blank')
    expect(releaseLink).toHaveAttribute('rel', 'noopener noreferrer')

    const docsLink = screen.getByTestId('operator-update-docs-link')
    expect(docsLink).toHaveAttribute(
      'href',
      'https://github.com/silvio-l/Votepit/blob/main/documentation/installation.md#upgrading-an-existing-installation',
    )
    expect(docsLink).toHaveAttribute('target', '_blank')
    expect(docsLink).toHaveAttribute('rel', 'noopener noreferrer')
  })

  it('renders nothing when no update is available', async () => {
    mockUpdateFetch(jsonResponse(UP_TO_DATE_RESPONSE))

    const { container } = render(<OperatorUpdateBanner />)

    await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled())
    // Let the resolved response flush before asserting absence.
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(screen.queryByTestId('operator-update-banner')).not.toBeInTheDocument()
    expect(screen.queryByTestId('operator-update-release-link')).not.toBeInTheDocument()
    expect(screen.queryByTestId('operator-update-docs-link')).not.toBeInTheDocument()
    expect(container).toBeEmptyDOMElement()
  })

  it('renders nothing and shows no error when the status request rejects', async () => {
    mockUpdateFetch(async () => {
      throw new TypeError('Failed to fetch')
    })

    const { container } = render(<OperatorUpdateBanner />)

    await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled())
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(screen.queryByTestId('operator-update-banner')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(container).toBeEmptyDOMElement()
  })
})

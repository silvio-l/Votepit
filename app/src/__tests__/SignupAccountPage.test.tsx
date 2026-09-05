/**
 * RTL tests for SignupAccountPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made. Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Anon (user: null) → redirect to /login with return-to
 *  3. Already has an account (has_account: true) → informational message, no form
 *  4. Fresh user: slug auto-suggestion for both account and board fields
 *  5. Field-error mapping from the API's 422 response
 *  6. Already-has-account 409 on submit (race) → general error shown
 *  7. Success: shows the new board's path as a live link into the new account
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SignupAccountPage from '../pages/SignupAccountPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const USER_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: { id: 7, is_admin: false, is_operator: false, memberships: [] },
}
const USER_WITH_ACCOUNT_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: {
    id: 7,
    is_admin: false,
    is_operator: false,
    memberships: [{ account_slug: 'acme', role: 'owner' as const }],
  },
}
const ANON_BOOTSTRAP = { csrf_token: 'test-csrf', user: null }

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Build a sequential fetch mock from a list of response payloads. */
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

/**
 * Route-keyed fetch mock ("METHOD urlSuffix" => response) instead of
 * sequential — background polling (e.g. the notifications bell) can
 * interleave an extra fetch at any point, which would silently desync a
 * purely sequential mock. Records every call for assertions on which
 * URLs/bodies were actually hit.
 */
function makeRoutedFetchMock(routes: Record<string, { body: object; status?: number }>) {
  const calls: Array<{ url: string; method: string; body: unknown }> = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init?: RequestInit) => {
    const urlStr = String(url)
    const method = init?.method ?? 'GET'
    calls.push({
      url: urlStr,
      method,
      body: init?.body != null ? JSON.parse(String(init.body)) : null,
    })

    const match = Object.entries(routes).find(([key]) => {
      const [routeMethod, ...rest] = key.split(' ')
      return routeMethod === method && urlStr.endsWith(rest.join(' '))
    })
    const r = match?.[1] ?? { body: {}, status: 404 }
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return { calls }
}

function renderSignupAccountPage() {
  return render(
    <MemoryRouter initialEntries={['/signup/account']}>
      <Routes>
        <Route path="/signup/account" element={<SignupAccountPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
  sessionStorage.clear()
})

describe('SignupAccountPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([{ body: USER_BOOTSTRAP }, { body: { has_account: false } }])

    renderSignupAccountPage()

    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('SignupAccountPage — anon redirect', () => {
  it('redirects anon user (user: null) to /login with return-to', async () => {
    makeFetchMock([{ body: ANON_BOOTSTRAP }])

    renderSignupAccountPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('SignupAccountPage — already has an account', () => {
  it('shows an informational message and a CTA to the existing account', async () => {
    makeFetchMock([{ body: USER_WITH_ACCOUNT_BOOTSTRAP }, { body: { has_account: true } }])

    renderSignupAccountPage()

    await waitFor(() =>
      expect(screen.getByText(/du hast bereits einen account/i)).toBeInTheDocument(),
    )
    expect(screen.queryByLabelText(/^Name/i)).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /zur verwaltung/i })).toHaveAttribute(
      'href',
      '/acme/admin/boards',
    )
  })
})

describe('SignupAccountPage — form: slug auto-suggestion', () => {
  it('proposes slugs from the account and board names, editable afterwards', async () => {
    makeFetchMock([{ body: USER_BOOTSTRAP }, { body: { has_account: false } }])

    renderSignupAccountPage()
    await waitFor(() => expect(document.getElementById('signup-account-name')).toBeInTheDocument())

    const user = userEvent.setup()
    const accountNameInput = document.getElementById('signup-account-name') as HTMLInputElement
    await user.type(accountNameInput, 'Acme Inc')

    const accountSlugInput = document.getElementById('signup-account-slug') as HTMLInputElement
    await waitFor(() => expect(accountSlugInput.value).toBe('acme-inc'))

    const boardNameInput = document.getElementById('signup-board-name') as HTMLInputElement
    await user.type(boardNameInput, 'Produkt Feedback')

    const boardSlugInput = document.getElementById('signup-board-slug') as HTMLInputElement
    await waitFor(() => expect(boardSlugInput.value).toBe('produkt-feedback'))

    // Manual edit of the account slug sticks — further name typing must not override it.
    await user.clear(accountSlugInput)
    await user.type(accountSlugInput, 'custom-slug')
    await user.type(accountNameInput, '!')
    expect(accountSlugInput.value).toBe('custom-slug')
  })
})

describe('SignupAccountPage — form: field-error mapping', () => {
  it('shows the reserved-slug error returned by the API on the account_slug field', async () => {
    makeFetchMock([
      { body: USER_BOOTSTRAP },
      { body: { has_account: false } },
      {
        body: {
          error: {
            key: 'validation_error',
            message: 'Validation failed.',
            fields: { account_slug: 'Dieser Slug ist reserviert.' },
          },
        },
        status: 422,
      },
    ])

    renderSignupAccountPage()
    await waitFor(() => expect(document.getElementById('signup-account-name')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(document.getElementById('signup-account-name') as HTMLInputElement, 'Signup')
    await user.type(document.getElementById('signup-board-name') as HTMLInputElement, 'Board')
    await user.click(screen.getByRole('button', { name: /account anlegen/i }))

    await waitFor(() => expect(screen.getByText('Dieser Slug ist reserviert.')).toBeInTheDocument())
  })
})

describe('SignupAccountPage — form: one-account-per-signup race', () => {
  it('shows a general error when the API rejects with 409 already_has_account', async () => {
    makeFetchMock([
      { body: USER_BOOTSTRAP },
      { body: { has_account: false } },
      {
        body: { error: { key: 'already_has_account', message: 'Du hast bereits einen Account.' } },
        status: 409,
      },
    ])

    renderSignupAccountPage()
    await waitFor(() => expect(document.getElementById('signup-account-name')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(document.getElementById('signup-account-name') as HTMLInputElement, 'Signup')
    await user.type(document.getElementById('signup-board-name') as HTMLInputElement, 'Board')
    await user.click(screen.getByRole('button', { name: /account anlegen/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent('Du hast bereits einen Account.')
  })
})

describe('SignupAccountPage — form: success', () => {
  it('shows the new board path as a live link after creation', async () => {
    makeFetchMock([
      { body: USER_BOOTSTRAP },
      { body: { has_account: false } },
      { body: { ok: true, account_slug: 'acme', board_slug: 'feedback' }, status: 201 },
    ])

    renderSignupAccountPage()
    await waitFor(() => expect(document.getElementById('signup-account-name')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(document.getElementById('signup-account-name') as HTMLInputElement, 'Acme')
    await user.type(document.getElementById('signup-board-name') as HTMLInputElement, 'Feedback')
    await user.click(screen.getByRole('button', { name: /account anlegen/i }))

    await waitFor(() =>
      expect(screen.getByRole('link', { name: /acme\/feedback/i })).toBeInTheDocument(),
    )
    expect(screen.getByRole('link', { name: /acme\/feedback/i })).toHaveAttribute(
      'href',
      '/acme/feedback',
    )
  })
})

describe('SignupAccountPage — referral capture (social-features ticket 01)', () => {
  it('calls POST /referrals/capture with the stashed ref after a successful signup', async () => {
    sessionStorage.setItem('vp_referral_ref', 'acme-referrer')

    const { calls } = makeRoutedFetchMock({
      'GET /api/bootstrap': { body: USER_BOOTSTRAP },
      'GET /signup/account': { body: { has_account: false } },
      'POST /signup/account': {
        body: { ok: true, account_slug: 'acme', board_slug: 'feedback' },
        status: 201,
      },
      'POST /referrals/capture': { body: { ok: true, recorded: true } },
    })

    renderSignupAccountPage()
    await waitFor(() => expect(document.getElementById('signup-account-name')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(document.getElementById('signup-account-name') as HTMLInputElement, 'Acme')
    await user.type(document.getElementById('signup-board-name') as HTMLInputElement, 'Feedback')
    await user.click(screen.getByRole('button', { name: /account anlegen/i }))

    await waitFor(() =>
      expect(screen.getByRole('link', { name: /acme\/feedback/i })).toBeInTheDocument(),
    )

    const captureCall = calls.find((c) => c.url.includes('/referrals/capture'))
    expect(captureCall).toBeDefined()
    expect(captureCall?.body).toEqual({ referrer_slug: 'acme-referrer' })

    // One-shot: the stashed ref is cleared once consumed.
    expect(sessionStorage.getItem('vp_referral_ref')).toBeNull()
  })

  it('never calls POST /referrals/capture when no ref was stashed', async () => {
    const { calls } = makeRoutedFetchMock({
      'GET /api/bootstrap': { body: USER_BOOTSTRAP },
      'GET /signup/account': { body: { has_account: false } },
      'POST /signup/account': {
        body: { ok: true, account_slug: 'acme', board_slug: 'feedback' },
        status: 201,
      },
    })

    renderSignupAccountPage()
    await waitFor(() => expect(document.getElementById('signup-account-name')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(document.getElementById('signup-account-name') as HTMLInputElement, 'Acme')
    await user.type(document.getElementById('signup-board-name') as HTMLInputElement, 'Feedback')
    await user.click(screen.getByRole('button', { name: /account anlegen/i }))

    await waitFor(() =>
      expect(screen.getByRole('link', { name: /acme\/feedback/i })).toBeInTheDocument(),
    )

    expect(calls.some((c) => c.url.includes('/referrals/capture'))).toBe(false)
  })
})

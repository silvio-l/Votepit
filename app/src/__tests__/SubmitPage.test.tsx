/**
 * RTL tests for SubmitPage — user-visible behaviour only (AC4).
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Inline field errors when API returns 422 with field-level messages
 *  2. General error when API returns 422 with no field errors (e.g. moderation block)
 *  3. Successful submit calls createIdea and navigates to idea detail
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setVoterPreview } from '../lib/voterPreview'
import SubmitPage from '../pages/SubmitPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}
const OWNER_BOOTSTRAP_RESPONSE = {
  csrf_token: 'test-csrf',
  user: {
    id: 1,
    is_admin: false,
    is_operator: false,
    memberships: [{ account_slug: 'demo', role: 'owner' }],
  },
}
const FORM_DATA_RESPONSE = {
  board: { id: 1, slug: 'demo', name: 'Demo Board' },
  is_authenticated: true,
  form_at: 'fake-stamp',
}

/**
 * Routes by request URL rather than call order — the debounced duplicate
 * search can fire zero or more times interleaved with bootstrap/
 * form-data/submit, so a fixed call-index sequence is no longer reliable.
 */
function makeFetchMock(
  submitResponse: object,
  submitStatus = 200,
  duplicatesResponse: object = { candidates: [] },
) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : (input as Request).url

    if (url.includes('/ideas/search-duplicates')) {
      return new Response(JSON.stringify(duplicatesResponse), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/api/bootstrap')) {
      return new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/ideas/new')) {
      return new Response(JSON.stringify(FORM_DATA_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    // POST /{board}/ideas — submit
    return new Response(JSON.stringify(submitResponse), {
      status: submitStatus,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderSubmitPage(boardSlug = 'demo') {
  return render(
    <MemoryRouter initialEntries={[`/${boardSlug}/submit`]}>
      <Routes>
        <Route path="/:boardSlug/submit" element={<SubmitPage />} />
        {/* Target after success navigate */}
        <Route path="/:boardSlug/idea/:ideaId" element={<div data-testid="idea-detail" />} />
        {/* Login redirect target */}
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderSubmitPageAt(entry: string) {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/:boardSlug/submit" element={<SubmitPage />} />
        <Route path="/:boardSlug/idea/:ideaId" element={<div data-testid="idea-detail" />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

function makeFetchMockWithBootstrap(bootstrap: object) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    if (url.includes('/api/bootstrap')) {
      return new Response(JSON.stringify(bootstrap), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/ideas/search-duplicates')) {
      return new Response(JSON.stringify({ candidates: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    return new Response(JSON.stringify(FORM_DATA_RESPONSE), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
  setVoterPreview(false)
})

describe('SubmitPage — inline validation', () => {
  it('shows title field error when API returns 422 with title field message', async () => {
    makeFetchMock(
      {
        error: {
          key: 'validation_error',
          message: 'Validation failed.',
          fields: { title: 'Der Titel muss mindestens 3 Zeichen lang sein.' },
          values: { title: 'ab' },
        },
      },
      422,
    )

    renderSubmitPage()

    // Wait for form to load
    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'ab')
    await user.type(screen.getByLabelText(/Beschreibung/i), 'Eine Beschreibung die lang genug ist.')
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Der Titel muss mindestens 3 Zeichen lang sein.'),
      ).toBeInTheDocument(),
    )

    // Body field should NOT show an error
    expect(screen.queryByText(/Beschreibung darf nicht leer sein/i)).not.toBeInTheDocument()
  })

  it('shows body field error when API returns 422 with body field message', async () => {
    makeFetchMock(
      {
        error: {
          key: 'validation_error',
          message: 'Validation failed.',
          fields: { body: 'Die Beschreibung darf nicht leer sein.' },
          values: { title: 'Gültiger Titel', body: '' },
        },
      },
      422,
    )

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'Gültiger Titel')
    // Leave body empty — submit anyway
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    await waitFor(() =>
      expect(screen.getByText('Die Beschreibung darf nicht leer sein.')).toBeInTheDocument(),
    )
  })

  it('shows general error for non-field 422 (e.g. moderation block)', async () => {
    makeFetchMock(
      {
        error: {
          key: 'moderation_blocked',
          message: 'Dein Text enthält unzulässige Begriffe. Bitte formuliere ihn um.',
          fields: {},
        },
      },
      422,
    )

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'Titel mit problematischem Inhalt')
    await user.type(screen.getByLabelText(/Beschreibung/i), 'Beschreibung mit Problemen.')
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent(
        'Dein Text enthält unzulässige Begriffe. Bitte formuliere ihn um.',
      ),
    )
  })
})

describe('SubmitPage — success flow', () => {
  it('calls createIdea and navigates to idea detail on 201', async () => {
    makeFetchMock({ ok: true, id: 42 }, 201)

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'Meine tolle Idee')
    await user.type(
      screen.getByLabelText(/Beschreibung/i),
      'Eine ausführliche Beschreibung der Idee.',
    )
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    // Should navigate to idea detail — the route renders our stub
    await waitFor(() => expect(screen.getByTestId('idea-detail')).toBeInTheDocument())
  })
})

describe('SubmitPage — duplicate hint', () => {
  it('shows a duplicate candidate hint after debounced typing', async () => {
    makeFetchMock({ ok: true, id: 99 }, 201, {
      candidates: [
        {
          id: 7,
          title: 'Dunkelmodus fürs Dashboard',
          status: 'open',
          up_count: 5,
          down_count: 1,
          similarity: 0.93,
        },
      ],
    })

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'Dunkelmodus fuers Dashboard')

    await waitFor(
      () => expect(screen.getByText('Dunkelmodus fürs Dashboard')).toBeInTheDocument(),
      { timeout: 2000 },
    )
    expect(screen.getByText(/Gibt es diese Idee vielleicht schon\?/i)).toBeInTheDocument()
    expect(screen.getByText(/4 Stimmen/)).toBeInTheDocument()
  })

  it('dismissing the hint hides it and submitting still works', async () => {
    makeFetchMock({ ok: true, id: 99 }, 201, {
      candidates: [
        {
          id: 7,
          title: 'Dunkelmodus fürs Dashboard',
          status: 'open',
          up_count: 5,
          down_count: 1,
          similarity: 0.93,
        },
      ],
    })

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'Dunkelmodus fuers Dashboard')

    await waitFor(
      () => expect(screen.getByText('Dunkelmodus fürs Dashboard')).toBeInTheDocument(),
      { timeout: 2000 },
    )

    await user.click(screen.getByRole('button', { name: /Trotzdem als neue Idee einreichen/i }))

    await waitFor(() =>
      expect(screen.queryByText('Dunkelmodus fürs Dashboard')).not.toBeInTheDocument(),
    )

    await user.type(
      screen.getByLabelText(/Beschreibung/i),
      'Eine ausführliche Beschreibung der Idee.',
    )
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    await waitFor(() => expect(screen.getByTestId('idea-detail')).toBeInTheDocument())
  })

  it('does not search for very short titles', async () => {
    makeFetchMock({ ok: true, id: 99 }, 201, {
      candidates: [
        {
          id: 7,
          title: 'Should never surface',
          status: 'open',
          up_count: 0,
          down_count: 0,
          similarity: 0.99,
        },
      ],
    })

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'ab')

    // Give the debounce window time to fire, then assert nothing was surfaced.
    await new Promise((resolve) => setTimeout(resolve, 500))
    expect(screen.queryByText('Should never surface')).not.toBeInTheDocument()
  })
})

describe('SubmitPage — anon redirect', () => {
  it('redirects anon user to login with return-to', async () => {
    let callIndex = 0
    const responses = [
      { body: JSON.stringify({ csrf_token: 'test', user: null }), status: 200 },
      {
        body: JSON.stringify({
          board: { id: 1, slug: 'demo', name: 'Demo' },
          is_authenticated: false,
          form_at: '',
        }),
        status: 200,
      },
    ]
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      const r = responses[callIndex] ?? responses[responses.length - 1]
      callIndex++
      return new Response(r.body, {
        status: r.status,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderSubmitPage()

    // Should navigate to the login page
    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('SubmitPage — voter-preview propagation', () => {
  it('landing with ?view=voter hides the Settings link for an owner', async () => {
    makeFetchMockWithBootstrap(OWNER_BOOTSTRAP_RESPONSE)

    renderSubmitPageAt('/demo/submit?view=voter')

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Idee einreichen' })).toBeInTheDocument(),
    )
    expect(screen.queryByRole('link', { name: 'Einstellungen' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Voter-Vorschau beenden' })).toBeInTheDocument()
  })

  it('owner without the preview toggle still sees the Settings link', async () => {
    makeFetchMockWithBootstrap(OWNER_BOOTSTRAP_RESPONSE)

    renderSubmitPage()

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Idee einreichen' })).toBeInTheDocument(),
    )
    expect(screen.getByRole('link', { name: 'Einstellungen' })).toBeInTheDocument()
  })
})

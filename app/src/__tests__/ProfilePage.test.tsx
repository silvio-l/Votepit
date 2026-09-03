/**
 * RTL tests for ProfilePage's avatar-upload and social-links sections
 * (profile-avatar-social security redesign). fetch is mocked globally; no
 * real network calls or actual image decoding happen — client-side
 * validation (type/size, and per-field social identifier grammar) is
 * exercised directly, server calls are exercised via mocked responses.
 *
 * Tests cover:
 *  - Loaded state shows the current avatar/social identifiers from getAccountProfile()
 *  - Client-side rejection of a non-image file type (no upload request sent)
 *  - Client-side rejection of an oversized file (no upload request sent)
 *  - Successful upload replaces the displayed avatar
 *  - Remove clears the avatar
 *  - Filling in the 4 fixed social fields and saving posts the normalized values
 *  - A full URL entered as the website is rejected client-side (no save request sent)
 *  - A leading "@" on the YouTube handle is rejected client-side
 *  - Exactly 4 fixed fields render — no add/remove-row UI exists anymore
 *  - Privacy switch (profile-visibility): reflects the loaded value, PUTs the
 *    new value on toggle, rolls back on a server error
 */

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProfilePage from '../pages/ProfilePage'

const EMPTY_SOCIAL_LINKS = {
  website_domain: null,
  x_handle: null,
  youtube_handle: null,
  github_username: null,
}

const BOOTSTRAP_AUTHED = {
  csrf_token: 'test-csrf',
  user: {
    id: 1,
    is_admin: false,
    is_operator: false,
    memberships: [{ account_slug: 'self', role: 'owner' }],
  },
}

function jsonResponse(body: object, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

/**
 * SecuritySettings' password form and UsernameSection also have a
 * "Speichern" button on this page, so `getByRole('button', { name:
 * 'Speichern' })` is ambiguous — scope to the Social-Links <section>.
 */
function getSocialLinksSaveButton(): HTMLElement {
  const heading = screen.getByText('Social-Links')
  const section = heading.closest('section')
  if (section === null) {
    throw new Error('social links section not found')
  }
  return within(section).getByRole('button', { name: 'Speichern' })
}

/** Queues fixed responses in order; any call beyond the list repeats the last one. */
function queueFetch(responses: Response[]) {
  let callIndex = 0
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    // The header's notification bell fires its own GET /notifications on every
    // authenticated page — served out-of-band so it never consumes a slot from
    // this page's own response queue below.
    if (typeof input === 'string' && input.startsWith('/notifications')) {
      return jsonResponse({ notifications: [] })
    }
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return r
  })
}

/** The fetch calls this page's own logic made — excludes the header's notification-bell fetch. */
function nonNotificationCalls(fetchMock: ReturnType<typeof queueFetch>) {
  return fetchMock.mock.calls.filter(
    ([url]) => !(typeof url === 'string' && url.startsWith('/notifications')),
  )
}

function renderProfilePage() {
  return render(
    <MemoryRouter initialEntries={['/profile']}>
      <Routes>
        <Route path="/profile" element={<ProfilePage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('ProfilePage — avatar section', () => {
  it('shows the current avatar image once loaded', async () => {
    queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: '/avatar/existing.jpg', ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()

    await waitFor(() =>
      expect(document.querySelector('img[src="/avatar/existing.jpg"]')).not.toBeNull(),
    )
    expect(screen.getByRole('button', { name: /Entfernen/ })).toBeInTheDocument()
  })

  it('rejects a non-image file client-side and never calls the upload endpoint', async () => {
    // Uses fireEvent (not userEvent.upload) deliberately: userEvent.upload()
    // itself filters by the input's `accept` attribute, mirroring a real
    // file-picker dialog — it would never even offer the .svg file. This
    // test exercises the JS-level defense-in-depth check instead (relevant
    // e.g. for drag-and-drop, which bypasses `accept` filtering).
    const fetchMock = queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Profilbild')).toBeInTheDocument())

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement
    const badFile = new File(['<svg></svg>'], 'evil.svg', { type: 'image/svg+xml' })
    fireEvent.change(fileInput, { target: { files: [badFile] } })

    await waitFor(() => expect(screen.getByText(/SVG wird nicht unterstützt/)).toBeInTheDocument())
    // Only the two initial bootstrap/profile calls happened — no POST /account/avatar.
    expect(nonNotificationCalls(fetchMock)).toHaveLength(2)
  })

  it('rejects an oversized file client-side and never calls the upload endpoint', async () => {
    const fetchMock = queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Profilbild')).toBeInTheDocument())

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement
    const tooBig = new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'huge.jpg', {
      type: 'image/jpeg',
    })
    fireEvent.change(fileInput, { target: { files: [tooBig] } })

    await waitFor(() => expect(screen.getByText(/größer als 5 MB/)).toBeInTheDocument())
    expect(nonNotificationCalls(fetchMock)).toHaveLength(2)
  })

  it('uploads a valid file and shows the new avatar returned by the server', async () => {
    queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, ...EMPTY_SOCIAL_LINKS }),
      jsonResponse({ ok: true, avatar_url: '/avatar/newfile.jpg' }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Profilbild')).toBeInTheDocument())

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement
    const goodFile = new File(['bytes'], 'photo.jpg', { type: 'image/jpeg' })

    const user = userEvent.setup()
    await user.upload(fileInput, goodFile)

    await waitFor(() =>
      expect(document.querySelector('img[src="/avatar/newfile.jpg"]')).not.toBeNull(),
    )
  })

  it('removes the avatar and falls back to the placeholder', async () => {
    queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: '/avatar/existing.jpg', ...EMPTY_SOCIAL_LINKS }),
      jsonResponse({ ok: true }),
    ])

    renderProfilePage()
    await waitFor(() =>
      expect(document.querySelector('img[src="/avatar/existing.jpg"]')).not.toBeNull(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Entfernen/ }))

    await waitFor(() => expect(document.querySelector('img')).toBeNull())
  })
})

describe('ProfilePage — social links section', () => {
  it('renders exactly 4 fixed identifier fields, pre-filled from getAccountProfile()', async () => {
    queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({
        avatar_url: null,
        website_domain: 'example.com',
        x_handle: 'myhandle',
        youtube_handle: 'my-channel',
        github_username: 'octocat',
      }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Social-Links')).toBeInTheDocument())

    expect(screen.getByDisplayValue('example.com')).toBeInTheDocument()
    expect(screen.getByDisplayValue('myhandle')).toBeInTheDocument()
    expect(screen.getByDisplayValue('my-channel')).toBeInTheDocument()
    expect(screen.getByDisplayValue('octocat')).toBeInTheDocument()

    // No dynamic add/remove-row UI exists anymore — exactly 4 fixed fields.
    expect(screen.queryByRole('button', { name: 'Link hinzufügen' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Entfernen' })).not.toBeInTheDocument()
  })

  it('populates the fields once getAccountProfile() resolves AFTER the page is already rendered', async () => {
    // Regression test: the page shows its "ready" state as soon as bootstrap()
    // resolves — /account/profile is fetched separately, afterwards. This
    // deliberately keeps that second fetch pending past the initial render so
    // SocialLinksSection mounts once with empty data (matching what always
    // happens in the real app, just usually too fast to notice) — reproduces
    // the bug where its local `form` state was seeded once from empty props
    // and then never re-synced when the real data arrived.
    let resolveProfile!: (value: Response) => void
    const profilePromise = new Promise<Response>((resolve) => {
      resolveProfile = resolve
    })
    let callIndex = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      callIndex++
      if (callIndex === 1) return jsonResponse(BOOTSTRAP_AUTHED)
      return profilePromise
    })

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Social-Links')).toBeInTheDocument())
    // At this point the section has already mounted with empty fields.
    expect(screen.getByLabelText('Website')).toHaveValue('')

    resolveProfile(
      jsonResponse({
        avatar_url: null,
        website_domain: 'example.com',
        x_handle: 'myhandle',
        youtube_handle: 'my-channel',
        github_username: 'octocat',
      }),
    )

    await waitFor(() => expect(screen.getByDisplayValue('example.com')).toBeInTheDocument())
    expect(screen.getByDisplayValue('myhandle')).toBeInTheDocument()
    expect(screen.getByDisplayValue('my-channel')).toBeInTheDocument()
    expect(screen.getByDisplayValue('octocat')).toBeInTheDocument()
  })

  it('fills in the 4 fields and saves the normalized values', async () => {
    const fetchMock = queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, ...EMPTY_SOCIAL_LINKS }),
      jsonResponse({ ok: true }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Social-Links')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Website'), 'example.com')
    // Leading "@" is accepted and stripped for X, mirroring the server.
    await user.type(screen.getByLabelText('X (Twitter)'), '@myhandle')
    await user.type(screen.getByLabelText('YouTube'), 'my-channel')
    await user.type(screen.getByLabelText('GitHub'), 'octocat')

    await user.click(getSocialLinksSaveButton())

    await waitFor(() => expect(screen.getByText('Gespeichert.')).toBeInTheDocument())

    const saveCall = nonNotificationCalls(fetchMock)[2] as [string, RequestInit]
    expect(saveCall[0]).toBe('/account/social-links')
    const sentBody = JSON.parse(saveCall[1].body as string)
    expect(sentBody).toEqual({
      website_domain: 'example.com',
      x_handle: 'myhandle',
      youtube_handle: 'my-channel',
      github_username: 'octocat',
    })
  })

  it('rejects a full URL entered as the website client-side and never calls the save endpoint', async () => {
    const fetchMock = queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Social-Links')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Website'), 'https://insecure.example.com')
    await user.click(getSocialLinksSaveButton())

    await waitFor(() => expect(screen.getByText(/reine Domain angeben/)).toBeInTheDocument())
    expect(nonNotificationCalls(fetchMock)).toHaveLength(2)
  })

  it('rejects a leading "@" on the YouTube handle client-side', async () => {
    const fetchMock = queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Social-Links')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('YouTube'), '@my-channel')
    await user.click(getSocialLinksSaveButton())

    await waitFor(() =>
      expect(screen.getByText(/gültigen YouTube-Handle ohne/)).toBeInTheDocument(),
    )
    expect(nonNotificationCalls(fetchMock)).toHaveLength(2)
  })
})

describe('ProfilePage — privacy section (profile-visibility)', () => {
  it('starts anonymous (switch off) when the profile says profile_visible: false', async () => {
    queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Datenschutz')).toBeInTheDocument())

    const toggle = screen.getByRole('switch', { name: 'Profil öffentlich sichtbar' })
    expect(toggle).toHaveAttribute('aria-checked', 'false')
    expect(screen.getByText(/als „Voter"/)).toBeInTheDocument()
  })

  it('reflects profile_visible: true from the loaded profile', async () => {
    queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, profile_visible: true, ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()
    await waitFor(() =>
      expect(screen.getByRole('switch', { name: 'Profil öffentlich sichtbar' })).toHaveAttribute(
        'aria-checked',
        'true',
      ),
    )
  })

  it('switching on sends PUT /account/privacy with visible:true and confirms', async () => {
    const fetchMock = queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
      jsonResponse({ ok: true, profile_visible: true }),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Datenschutz')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByRole('switch', { name: 'Profil öffentlich sichtbar' }))

    await waitFor(() => expect(screen.getByText('Gespeichert.')).toBeInTheDocument())
    expect(screen.getByRole('switch', { name: 'Profil öffentlich sichtbar' })).toHaveAttribute(
      'aria-checked',
      'true',
    )

    const saveCall = nonNotificationCalls(fetchMock)[2] as [string, RequestInit]
    expect(saveCall[0]).toBe('/account/privacy')
    expect(saveCall[1].method).toBe('PUT')
    expect(JSON.parse(saveCall[1].body as string)).toEqual({ visible: true })
  })

  it('rolls the switch back and shows an error when the server rejects the change', async () => {
    queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
      jsonResponse({ error: { key: 'server_error', message: 'Boom' } }, 500),
    ])

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Datenschutz')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByRole('switch', { name: 'Profil öffentlich sichtbar' }))

    await waitFor(() =>
      expect(screen.getByText(/Einstellung konnte nicht gespeichert werden/)).toBeInTheDocument(),
    )
    expect(screen.getByRole('switch', { name: 'Profil öffentlich sichtbar' })).toHaveAttribute(
      'aria-checked',
      'false',
    )
  })
})

describe('ProfilePage — profile load failure', () => {
  it('shows a retry banner instead of silently rendering empty avatar/social-links/privacy state', async () => {
    queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ error: { key: 'server_error', message: 'Boom' } }, 500),
    ])

    renderProfilePage()

    await waitFor(() =>
      expect(
        screen.getByText(/Profilbild, Social-Links und Datenschutz-Einstellung konnten nicht/),
      ).toBeInTheDocument(),
    )
    // The rest of the page still renders (avatar/social-links/privacy
    // sections are present, just empty) — this is a visible error, not a
    // blank page.
    expect(screen.getByText('Social-Links')).toBeInTheDocument()
  })

  it('retry re-fetches the profile and clears the error on success', async () => {
    const fetchMock = queueFetch([
      jsonResponse(BOOTSTRAP_AUTHED),
      jsonResponse({ error: { key: 'server_error', message: 'Boom' } }, 500),
      jsonResponse({
        avatar_url: '/avatar/mine.jpg',
        profile_visible: true,
        ...EMPTY_SOCIAL_LINKS,
      }),
    ])

    renderProfilePage()
    await waitFor(() =>
      expect(
        screen.getByText(/Profilbild, Social-Links und Datenschutz-Einstellung konnten nicht/),
      ).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Erneut versuchen' }))

    await waitFor(() =>
      expect(document.querySelector('img[src="/avatar/mine.jpg"]')).not.toBeNull(),
    )
    expect(
      screen.queryByText(/Profilbild, Social-Links und Datenschutz-Einstellung konnten nicht/),
    ).not.toBeInTheDocument()
    expect(nonNotificationCalls(fetchMock)).toHaveLength(3)
  })
})

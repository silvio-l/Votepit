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

const DEFAULT_NOTIFICATION_PREFS = {
  notification_email: null,
  idea_comment_inapp: true,
  idea_comment_email: false,
  thread_reply_inapp: true,
  thread_reply_email: false,
}

/**
 * Queues fixed responses in order; any call beyond the list repeats the last
 * one. Two calls are served out-of-band and never consume a slot from this
 * queue: the header's notification bell (GET /notifications, fired on every
 * authenticated page) and this page's own notification-preferences section
 * (GET /account/notification-preferences, fired unconditionally on load by
 * NotificationPreferencesSection) — otherwise every pre-existing avatar/
 * social-links/privacy test in this file would need an extra queued
 * response purely for a section it isn't testing.
 */
function queueFetch(responses: Response[], notificationPrefs = DEFAULT_NOTIFICATION_PREFS) {
  let callIndex = 0
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = typeof input === 'string' ? input : input.toString()
    if (url.startsWith('/notifications')) {
      return jsonResponse({ notifications: [] })
    }
    if (url === '/account/notification-preferences' && (init?.method ?? 'GET') === 'GET') {
      return jsonResponse(notificationPrefs)
    }
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return r
  })
}

/** The fetch calls this page's own logic made — excludes the out-of-band calls above. */
function nonNotificationCalls(fetchMock: ReturnType<typeof queueFetch>) {
  return fetchMock.mock.calls.filter(([url, init]) => {
    const u = typeof url === 'string' ? url : url.toString()
    if (u.startsWith('/notifications')) return false
    if (
      u === '/account/notification-preferences' &&
      ((init as RequestInit | undefined)?.method ?? 'GET') === 'GET'
    ) {
      return false
    }
    return true
  })
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

describe('ProfilePage — notification preferences section', () => {
  it('reflects the loaded flags and confirmed email from the server', async () => {
    queueFetch(
      [
        jsonResponse(BOOTSTRAP_AUTHED),
        jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
      ],
      {
        notification_email: 'me@example.com',
        idea_comment_inapp: false,
        idea_comment_email: true,
        thread_reply_inapp: true,
        thread_reply_email: false,
      },
    )

    renderProfilePage()
    await waitFor(() => expect(screen.getByText(/me@example\.com/)).toBeInTheDocument())

    const ideaCommentRow = screen.getByText('Neuer Kommentar auf meiner Idee').closest('div')
    if (ideaCommentRow === null) throw new Error('idea-comment row not found')
    expect(within(ideaCommentRow).getByLabelText('In-App')).toHaveAttribute('aria-checked', 'false')
    expect(within(ideaCommentRow).getByLabelText('E-Mail')).toHaveAttribute('aria-checked', 'true')

    expect(screen.getByRole('button', { name: 'E-Mail entfernen' })).toBeInTheDocument()
  })

  it('switching an in-app checkbox sends a PUT with all four current flags and confirms', async () => {
    const fetchMock = queueFetch(
      [
        jsonResponse(BOOTSTRAP_AUTHED),
        jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
        jsonResponse({
          ok: true,
          idea_comment_inapp: false,
          idea_comment_email: false,
          thread_reply_inapp: true,
          thread_reply_email: false,
          abuse_report_inapp: false,
          abuse_report_email: false,
          support_ticket_inapp: false,
          support_ticket_email: false,
        }),
      ],
      DEFAULT_NOTIFICATION_PREFS,
    )

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Benachrichtigungen')).toBeInTheDocument())

    const ideaCommentRow = screen.getByText('Neuer Kommentar auf meiner Idee').closest('div')
    if (ideaCommentRow === null) throw new Error('idea-comment row not found')

    const user = userEvent.setup()
    await user.click(within(ideaCommentRow).getByLabelText('In-App'))

    await waitFor(() => expect(screen.getAllByText('Gespeichert.')).not.toHaveLength(0))
    expect(within(ideaCommentRow).getByLabelText('In-App')).toHaveAttribute('aria-checked', 'false')

    const saveCall = nonNotificationCalls(fetchMock)[2] as [string, RequestInit]
    expect(saveCall[0]).toBe('/account/notification-preferences')
    expect(saveCall[1].method).toBe('PUT')
    // Always sends all categories — including the operator-only ones this
    // non-operator user never sees in the UI — so the backend never
    // interprets an absent key as "turn this off" (security-review finding,
    // 2026-09-05: a stale/partial client payload must not silently disable
    // an always-on-by-default preference like abuse-report notifications).
    expect(JSON.parse(saveCall[1].body as string)).toEqual({
      idea_comment_inapp: false,
      idea_comment_email: false,
      thread_reply_inapp: true,
      thread_reply_email: false,
      abuse_report_inapp: false,
      abuse_report_email: false,
      support_ticket_inapp: false,
      support_ticket_email: false,
    })
  })

  it('rolls the checkbox back and shows an error when the server rejects the change', async () => {
    queueFetch(
      [
        jsonResponse(BOOTSTRAP_AUTHED),
        jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
        jsonResponse({ error: { key: 'server_error', message: 'Boom' } }, 500),
      ],
      DEFAULT_NOTIFICATION_PREFS,
    )

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Benachrichtigungen')).toBeInTheDocument())

    const ideaCommentRow = screen.getByText('Neuer Kommentar auf meiner Idee').closest('div')
    if (ideaCommentRow === null) throw new Error('idea-comment row not found')

    const user = userEvent.setup()
    await user.click(within(ideaCommentRow).getByLabelText('In-App'))

    await waitFor(() =>
      expect(
        screen.getAllByText(/Einstellung konnte nicht gespeichert werden/).length,
      ).toBeGreaterThan(0),
    )
    expect(within(ideaCommentRow).getByLabelText('In-App')).toHaveAttribute('aria-checked', 'true')
  })

  it('shows the email input only after activating an email checkbox with no confirmed address yet', async () => {
    queueFetch(
      [
        jsonResponse(BOOTSTRAP_AUTHED),
        jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
        jsonResponse({
          ok: true,
          idea_comment_inapp: true,
          idea_comment_email: true,
          thread_reply_inapp: true,
          thread_reply_email: false,
        }),
      ],
      DEFAULT_NOTIFICATION_PREFS,
    )

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Benachrichtigungen')).toBeInTheDocument())
    expect(screen.queryByLabelText('Benachrichtigungs-E-Mail')).not.toBeInTheDocument()

    const ideaCommentRow = screen.getByText('Neuer Kommentar auf meiner Idee').closest('div')
    if (ideaCommentRow === null) throw new Error('idea-comment row not found')

    const user = userEvent.setup()
    await user.click(within(ideaCommentRow).getByLabelText('E-Mail'))

    await waitFor(() =>
      expect(screen.getByLabelText('Benachrichtigungs-E-Mail')).toBeInTheDocument(),
    )
  })

  it('submitting the email field sends the request and shows the sent hint', async () => {
    const fetchMock = queueFetch(
      [
        jsonResponse(BOOTSTRAP_AUTHED),
        jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
        jsonResponse({
          ok: true,
          idea_comment_inapp: true,
          idea_comment_email: true,
          thread_reply_inapp: true,
          thread_reply_email: false,
        }),
        jsonResponse({ ok: true }),
      ],
      DEFAULT_NOTIFICATION_PREFS,
    )

    renderProfilePage()
    await waitFor(() => expect(screen.getByText('Benachrichtigungen')).toBeInTheDocument())

    const ideaCommentRow = screen.getByText('Neuer Kommentar auf meiner Idee').closest('div')
    if (ideaCommentRow === null) throw new Error('idea-comment row not found')

    const user = userEvent.setup()
    await user.click(within(ideaCommentRow).getByLabelText('E-Mail'))
    await waitFor(() =>
      expect(screen.getByLabelText('Benachrichtigungs-E-Mail')).toBeInTheDocument(),
    )

    await user.type(screen.getByLabelText('Benachrichtigungs-E-Mail'), 'notify-me@example.com')
    await user.click(screen.getByRole('button', { name: 'Bestätigungslink senden' }))

    await waitFor(() =>
      expect(
        screen.getByText('Bestätigungsmail verschickt — bitte den Link in der E-Mail anklicken.'),
      ).toBeInTheDocument(),
    )

    const submitCall = nonNotificationCalls(fetchMock)[3] as [string, RequestInit]
    expect(submitCall[0]).toBe('/account/notification-email')
    expect(submitCall[1].method).toBe('POST')
    expect(JSON.parse(submitCall[1].body as string)).toEqual({ email: 'notify-me@example.com' })
  })

  it('clicking "E-Mail entfernen" clears the confirmed address and disables both email checkboxes', async () => {
    const fetchMock = queueFetch(
      [
        jsonResponse(BOOTSTRAP_AUTHED),
        jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
        jsonResponse({ ok: true }),
      ],
      {
        notification_email: 'me@example.com',
        idea_comment_inapp: true,
        idea_comment_email: true,
        thread_reply_inapp: true,
        thread_reply_email: true,
      },
    )

    renderProfilePage()
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'E-Mail entfernen' })).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'E-Mail entfernen' }))

    await waitFor(() => expect(screen.queryByText(/me@example\.com/)).not.toBeInTheDocument())

    const ideaCommentRow = screen.getByText('Neuer Kommentar auf meiner Idee').closest('div')
    if (ideaCommentRow === null) throw new Error('idea-comment row not found')
    expect(within(ideaCommentRow).getByLabelText('E-Mail')).toHaveAttribute('aria-checked', 'false')

    const deleteCall = nonNotificationCalls(fetchMock)[2] as [string, RequestInit]
    expect(deleteCall[0]).toBe('/account/notification-email')
    expect(deleteCall[1].method).toBe('DELETE')
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

describe('ProfilePage — accounts section (pure voter, no membership)', () => {
  it('offers a CTA to create an own account instead of just a bare empty state', async () => {
    queueFetch([
      jsonResponse({
        csrf_token: 'test-csrf',
        user: { id: 7, is_admin: false, is_operator: false, memberships: [] },
      }),
      jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()

    await waitFor(() =>
      expect(screen.getByText('Du bist noch keinem Account zugeordnet.')).toBeInTheDocument(),
    )
    const cta = screen.getByRole('link', { name: 'Eigenes Board erstellen' })
    expect(cta).toHaveAttribute('href', '/signup')
  })

  it('shows only Profil (and Postfach) in the sidebar — no admin sections for a member-less user', async () => {
    queueFetch([
      jsonResponse({
        csrf_token: 'test-csrf',
        user: { id: 7, is_admin: false, is_operator: false, memberships: [] },
      }),
      jsonResponse({ avatar_url: null, profile_visible: false, ...EMPTY_SOCIAL_LINKS }),
    ])

    renderProfilePage()

    await waitFor(() =>
      expect(screen.getByText('Du bist noch keinem Account zugeordnet.')).toBeInTheDocument(),
    )
    const hrefs = screen.getAllByRole('link').map((l) => l.getAttribute('href'))
    expect(hrefs).not.toContain('/admin/boards')
    expect(hrefs).not.toContain('/admin/members')
    expect(hrefs).not.toContain('/admin/support')
    expect(hrefs).not.toContain('/admin/account')
  })
})

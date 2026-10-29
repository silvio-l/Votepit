/**
 * RTL tests for PersonalDataSection (ProfilePage — GDPR self-service:
 * "Download my data" + "Delete my account" with a 48h undo window).
 * The api module is mocked directly, same as SecuritySettings.test.tsx.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PersonalDataSection } from '../components/PersonalDataSection'
import * as api from '../lib/api'
import { ApiError } from '../lib/api'

vi.mock('../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../lib/api')>('../lib/api')
  return {
    ...actual,
    downloadMyData: vi.fn(),
    requestUserDeletion: vi.fn(),
    cancelUserDeletion: vi.fn(),
  }
})

beforeEach(() => {
  vi.restoreAllMocks()
  vi.clearAllMocks()
})

function renderSection(props: Partial<Parameters<typeof PersonalDataSection>[0]> = {}) {
  const onDeletionScheduledChange = vi.fn()
  render(
    <PersonalDataSection
      deletionScheduledAt={null}
      ownsAccount={false}
      onDeletionScheduledChange={onDeletionScheduledChange}
      {...props}
    />,
  )
  return { onDeletionScheduledChange }
}

describe('PersonalDataSection', () => {
  it('downloads the own data export', async () => {
    vi.mocked(api.downloadMyData).mockResolvedValue()
    renderSection()

    await userEvent.setup().click(screen.getByRole('button', { name: /daten herunterladen/i }))

    expect(api.downloadMyData).toHaveBeenCalledTimes(1)
  })

  it('only submits the deletion once an email is typed, and reports the deadline', async () => {
    vi.mocked(api.requestUserDeletion).mockResolvedValue({
      ok: true,
      deletion_scheduled_at: '2026-10-03T12:00:00+00:00',
    })
    const { onDeletionScheduledChange } = renderSection()
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: /konto löschen/i }))
    const confirm = screen.getByRole('button', { name: /löschung bestätigen/i })
    expect(confirm).toBeDisabled()

    await user.type(screen.getByLabelText(/e-mail-adresse/i), 'me@example.com')
    await user.click(confirm)

    await waitFor(() =>
      expect(onDeletionScheduledChange).toHaveBeenCalledWith('2026-10-03T12:00:00+00:00'),
    )
    expect(api.requestUserDeletion).toHaveBeenCalledWith('me@example.com')
  })

  it('shows the server message when the deletion is refused', async () => {
    vi.mocked(api.requestUserDeletion).mockRejectedValue(
      new ApiError(422, { key: 'confirmation_mismatch', message: 'Wrong email.' }),
    )
    renderSection()
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: /konto löschen/i }))
    await user.type(screen.getByLabelText(/e-mail-adresse/i), 'other@example.com')
    await user.click(screen.getByRole('button', { name: /löschung bestätigen/i }))

    expect(await screen.findByText('Wrong email.')).toBeInTheDocument()
  })

  it('blocks account owners up front', () => {
    renderSection({ ownsAccount: true })

    expect(screen.getByRole('button', { name: /konto löschen/i })).toBeDisabled()
    expect(screen.getByText(/besitzt noch einen account/i)).toBeInTheDocument()
  })

  it('offers an undo while the deletion is pending', async () => {
    vi.mocked(api.cancelUserDeletion).mockResolvedValue({ ok: true })
    const { onDeletionScheduledChange } = renderSection({
      deletionScheduledAt: '2026-10-03T12:00:00+00:00',
    })

    expect(screen.queryByRole('button', { name: /konto löschen/i })).not.toBeInTheDocument()
    await userEvent.setup().click(screen.getByRole('button', { name: /löschung abbrechen/i }))

    await waitFor(() => expect(onDeletionScheduledChange).toHaveBeenCalledWith(null))
    expect(api.cancelUserDeletion).toHaveBeenCalledTimes(1)
  })
})

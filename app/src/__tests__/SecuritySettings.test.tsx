/**
 * RTL tests for SecuritySettings (ProfilePage's Password + 2FA sections).
 *
 * The api module is mocked directly (not fetch) — this component talks to
 * several distinct endpoints with different shapes, and mocking the module
 * keeps each test focused on the UI state machine rather than re-deriving
 * request/response plumbing already covered by api.ts's own usage elsewhere.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SecuritySettings } from '../components/SecuritySettings'
import * as api from '../lib/api'

vi.mock('../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../lib/api')>('../lib/api')
  return {
    ...actual,
    setPassword: vi.fn(),
    requestOwnPasswordReset: vi.fn(),
    beginTotpSetup: vi.fn(),
    confirmTotpSetup: vi.fn(),
    disableTotp: vi.fn(),
    regenerateBackupCodes: vi.fn(),
  }
})

function renderSecuritySettings(props: Partial<Parameters<typeof SecuritySettings>[0]> = {}) {
  return render(
    <SecuritySettings
      hasPassword={false}
      totpEnabled={false}
      onPasswordChanged={vi.fn()}
      onTotpEnabled={vi.fn()}
      onTotpDisabled={vi.fn()}
      {...props}
    />,
  )
}

beforeEach(() => {
  // restoreAllMocks() alone doesn't reset call history on the vi.fn() mocks
  // created in the module factory above (only spies on real implementations)
  // — clearAllMocks() additionally resets .mock.calls between tests.
  vi.restoreAllMocks()
  vi.clearAllMocks()
})

describe('SecuritySettings — password', () => {
  it('sets a first-time password without asking for a current one', async () => {
    vi.mocked(api.setPassword).mockResolvedValue({ ok: true })
    const onPasswordChanged = vi.fn()
    renderSecuritySettings({ hasPassword: false, onPasswordChanged })

    const user = userEvent.setup()
    expect(screen.queryByLabelText(/Aktuelles Passwort/i)).not.toBeInTheDocument()
    await user.type(
      document.getElementById('new-password') as HTMLInputElement,
      'a-strong-password',
    )
    await user.type(
      document.getElementById('new-password-confirmation') as HTMLInputElement,
      'a-strong-password',
    )
    await user.click(screen.getByRole('button', { name: /^speichern$/i }))

    await waitFor(() => expect(onPasswordChanged).toHaveBeenCalled())
    expect(api.setPassword).toHaveBeenCalledWith(
      'a-strong-password',
      'a-strong-password',
      undefined,
    )
  })

  it('requires the current password when one is already set', async () => {
    vi.mocked(api.setPassword).mockResolvedValue({ ok: true })
    renderSecuritySettings({ hasPassword: true })

    expect(screen.getByLabelText(/Aktuelles Passwort/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^speichern$/i })).toBeDisabled()
  })

  it('shows the server error on a rejected password change', async () => {
    const { ApiError } = await vi.importActual<typeof import('../lib/api')>('../lib/api')
    vi.mocked(api.setPassword).mockRejectedValue(
      new ApiError(400, { key: 'weak_password', message: 'Das Passwort ist zu kurz.' }),
    )
    renderSecuritySettings({ hasPassword: false })

    const user = userEvent.setup()
    await user.type(document.getElementById('new-password') as HTMLInputElement, 'short')
    await user.type(
      document.getElementById('new-password-confirmation') as HTMLInputElement,
      'short',
    )
    await user.click(screen.getByRole('button', { name: /^speichern$/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent('Das Passwort ist zu kurz.')
  })

  it('shows an inline error and disables submit when the confirmation does not match', async () => {
    renderSecuritySettings({ hasPassword: false })

    const user = userEvent.setup()
    await user.type(
      document.getElementById('new-password') as HTMLInputElement,
      'a-strong-password',
    )
    await user.type(
      document.getElementById('new-password-confirmation') as HTMLInputElement,
      'a-different-password',
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Die Passwörter stimmen nicht überein.')
    expect(screen.getByRole('button', { name: /^speichern$/i })).toBeDisabled()
    expect(api.setPassword).not.toHaveBeenCalled()
  })
})

describe('SecuritySettings — reset link', () => {
  it('sends a reset link once the confirmed email matches the account', async () => {
    vi.mocked(api.requestOwnPasswordReset).mockResolvedValue({ ok: true })
    renderSecuritySettings({ hasPassword: true })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /reset-link per e-mail zusenden/i }))
    await user.type(screen.getByLabelText(/E-Mail bestätigen/i), 'me@example.com')
    await user.click(screen.getByRole('button', { name: /^reset-link senden$/i }))

    await waitFor(() => expect(api.requestOwnPasswordReset).toHaveBeenCalledWith('me@example.com'))
    expect(screen.getByRole('status')).toHaveTextContent(/Prüfe dein Postfach/i)
  })

  it('shows a mismatch error and sends no mail on a wrong email', async () => {
    const { ApiError } = await vi.importActual<typeof import('../lib/api')>('../lib/api')
    vi.mocked(api.requestOwnPasswordReset).mockRejectedValue(
      new ApiError(422, { key: 'email_mismatch', message: 'nope' }),
    )
    renderSecuritySettings({ hasPassword: true })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /reset-link per e-mail zusenden/i }))
    await user.type(screen.getByLabelText(/E-Mail bestätigen/i), 'wrong@example.com')
    await user.click(screen.getByRole('button', { name: /^reset-link senden$/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Das stimmt nicht mit der E-Mail dieses Kontos überein.',
    )
  })
})

describe('SecuritySettings — TOTP setup', () => {
  it('walks through setup → confirm → backup codes → enabled', async () => {
    vi.mocked(api.beginTotpSetup).mockResolvedValue({
      secret: 'ABCDEFGHIJKLMNOP',
      provisioning_uri:
        'otpauth://totp/Votepit:Account%20%231?secret=ABCDEFGHIJKLMNOP&issuer=Votepit',
      setup_token: 'setup-token-abc',
    })
    vi.mocked(api.confirmTotpSetup).mockResolvedValue({
      ok: true,
      backup_codes: Array.from({ length: 10 }, (_, i) => `CODE${i}-CODE${i}`),
    })
    const onTotpEnabled = vi.fn()
    renderSecuritySettings({ totpEnabled: false, onTotpEnabled })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /2FA aktivieren/i }))

    await waitFor(() => expect(screen.getByText('ABCDEFGHIJKLMNOP')).toBeInTheDocument())

    await user.type(screen.getByLabelText(/^Code/i), '123456')
    await user.click(screen.getByRole('button', { name: /^bestätigen$/i }))

    await waitFor(() => expect(screen.getByText(/CODE0-CODE0/)).toBeInTheDocument())
    expect(screen.getAllByRole('listitem')).toHaveLength(10)

    await user.click(screen.getByRole('button', { name: /codes gespeichert/i }))

    expect(onTotpEnabled).toHaveBeenCalled()
    expect(screen.getByText(/2FA ist aktiv/i)).toBeInTheDocument()
  })

  it('shows an error and stays on the setup form for a wrong confirmation code', async () => {
    const { ApiError } = await vi.importActual<typeof import('../lib/api')>('../lib/api')
    vi.mocked(api.beginTotpSetup).mockResolvedValue({
      secret: 'ABCDEFGHIJKLMNOP',
      provisioning_uri: 'otpauth://totp/Votepit:Account%20%231?secret=ABCDEFGHIJKLMNOP',
      setup_token: 'setup-token-abc',
    })
    vi.mocked(api.confirmTotpSetup).mockRejectedValue(
      new ApiError(400, { key: 'invalid_code', message: 'Der Code ist ungültig oder abgelaufen.' }),
    )
    renderSecuritySettings({ totpEnabled: false })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /2FA aktivieren/i }))
    await waitFor(() => expect(screen.getByLabelText(/^Code/i)).toBeInTheDocument())

    await user.type(screen.getByLabelText(/^Code/i), '000000')
    await user.click(screen.getByRole('button', { name: /^bestätigen$/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent('Der Code ist ungültig oder abgelaufen.')
  })
})

describe('SecuritySettings — TOTP disable / regenerate', () => {
  it('disables 2FA after confirming with the current password', async () => {
    vi.mocked(api.disableTotp).mockResolvedValue({ ok: true })
    const onTotpDisabled = vi.fn()
    renderSecuritySettings({ totpEnabled: true, onTotpDisabled })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /2FA deaktivieren/i }))
    await user.type(screen.getByLabelText(/Aktuelles Passwort/i), 'my-password')
    await user.click(screen.getByRole('button', { name: /^2FA deaktivieren$/i }))

    await waitFor(() => expect(onTotpDisabled).toHaveBeenCalled())
    expect(api.disableTotp).toHaveBeenCalledWith({ currentPassword: 'my-password' })
    expect(screen.getByRole('button', { name: /2FA aktivieren/i })).toBeInTheDocument()
  })

  it('regenerates backup codes and shows them once', async () => {
    vi.mocked(api.regenerateBackupCodes).mockResolvedValue({
      ok: true,
      backup_codes: Array.from({ length: 10 }, (_, i) => `NEW${i}-NEW${i}`),
    })
    renderSecuritySettings({ totpEnabled: true })

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Backup-Codes neu erzeugen/i }))
    await user.type(screen.getByLabelText(/Aktuelles Passwort/i), 'my-password')
    await user.click(screen.getByRole('button', { name: /^Backup-Codes neu erzeugen$/i }))

    await waitFor(() => expect(screen.getByText(/NEW0-NEW0/)).toBeInTheDocument())
  })
})

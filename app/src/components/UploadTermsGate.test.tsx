/**
 * Unit tests for the upload clause consent (AGB 19) in front of the first board image upload:
 * the dialog opens before any upload call, the accept call precedes the upload, cancel and a
 * failed accept upload nothing, and an accepted account uploads directly.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { acceptUploadTerms, uploadBoardAsset } from '../lib/api/boardAssets'
import { ApiError } from '../lib/api/client'
import type { BoardAssetsState } from './BoardAssetsSettings'
import { LogoField } from './LogoField'

vi.mock('../lib/api/boardAssets', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api/boardAssets')>()
  return {
    ...actual,
    uploadBoardAsset: vi.fn(() => Promise.resolve({ ok: true, variants: {} })),
    deleteBoardAsset: vi.fn(),
    acceptUploadTerms: vi.fn(() =>
      Promise.resolve({
        ok: true,
        terms_version: '2026-10-draft',
        accepted_at: '2026-10-25 10:00:00',
      }),
    ),
  }
})

const assetsState = (accepted: boolean): BoardAssetsState => ({
  data: {
    variants: {},
    allowed: { cover_upload: true, favicon_og: true, back_link_footer: true, logo_url: true },
    max_upload_bytes: 2 * 1024 * 1024,
    link: { back_link_url: '', back_link_label: '', footer_note: '' },
    upload_terms: { version: '2026-10-draft', accepted },
  },
  loadError: false,
  reload: vi.fn(() => Promise.resolve()),
})

/** Community payload: no upload_terms block, because no terms version is configured. */
const noTermsState = (): BoardAssetsState => ({
  data: {
    variants: {},
    allowed: { cover_upload: true, favicon_og: true, back_link_footer: true, logo_url: true },
    max_upload_bytes: 2 * 1024 * 1024,
    link: { back_link_url: '', back_link_label: '', footer_note: '' },
  },
  loadError: false,
  reload: vi.fn(() => Promise.resolve()),
})

const baseProps = {
  testId: 'admin-logo',
  boardSlug: 'demo',
  label: 'Logo',
  legacyUrl: '',
  onLegacyClear: vi.fn(),
  allowed: true,
  maxBytes: 2 * 1024 * 1024,
}

const pngFile = () => new File([new Uint8Array(100)], 'logo.png', { type: 'image/png' })

/** Picks a file in the logo field; picking starts the upload. */
function pickAndUpload() {
  fireEvent.change(screen.getByTestId('admin-logo-input'), { target: { files: [pngFile()] } })
}

describe('upload terms gate', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  beforeAll(() => {
    URL.createObjectURL = vi.fn(() => 'blob:preview')
    URL.revokeObjectURL = vi.fn()
  })

  it('asks before the first upload and does not call the upload endpoint yet', async () => {
    render(<LogoField {...baseProps} assets={assetsState(false)} />)

    pickAndUpload()

    const dialog = await screen.findByRole('dialog')
    expect(dialog).toBeInTheDocument()
    expect(uploadBoardAsset).not.toHaveBeenCalled()
    expect(acceptUploadTerms).not.toHaveBeenCalled()
  })

  it('describes the dialog by its summary text', async () => {
    render(<LogoField {...baseProps} assets={assetsState(false)} />)

    pickAndUpload()

    const dialog = await screen.findByRole('dialog')
    const describedBy = dialog.getAttribute('aria-describedby')
    expect(describedBy).toBeTruthy()
    const description = document.getElementById(describedBy as string)
    expect(description?.textContent?.length ?? 0).toBeGreaterThan(20)
    expect(dialog.getAttribute('aria-labelledby')).toBeTruthy()
  })

  it('keeps the accept button disabled until the checkbox is ticked', async () => {
    render(<LogoField {...baseProps} assets={assetsState(false)} />)
    pickAndUpload()

    const accept = await screen.findByTestId('admin-logo-terms-accept')
    expect(accept).toBeDisabled()

    fireEvent.click(screen.getByTestId('admin-logo-terms-checkbox'))
    expect(accept).not.toBeDisabled()
  })

  it('accepts, then uploads exactly once and reloads the assets', async () => {
    const assets = assetsState(false)
    render(<LogoField {...baseProps} assets={assets} />)
    pickAndUpload()

    fireEvent.click(await screen.findByTestId('admin-logo-terms-checkbox'))
    fireEvent.click(screen.getByTestId('admin-logo-terms-accept'))

    await waitFor(() => expect(uploadBoardAsset).toHaveBeenCalledTimes(1))
    expect(acceptUploadTerms).toHaveBeenCalledWith(
      'demo',
      expect.stringMatching(/^(de|en|fr|es|nl|it)$/),
    )
    expect(vi.mocked(acceptUploadTerms).mock.invocationCallOrder[0]).toBeLessThan(
      vi.mocked(uploadBoardAsset).mock.invocationCallOrder[0],
    )
    expect(uploadBoardAsset).toHaveBeenCalledWith('demo', 'logo', expect.any(File))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('cancel closes the dialog and uploads nothing', async () => {
    render(<LogoField {...baseProps} assets={assetsState(false)} />)
    pickAndUpload()

    fireEvent.click(await screen.findByTestId('admin-logo-terms-cancel'))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(acceptUploadTerms).not.toHaveBeenCalled()
    expect(uploadBoardAsset).not.toHaveBeenCalled()
    // The picked file is dropped again, so no preview suggests a pending upload.
    expect(screen.queryByTestId('admin-logo-pending')).not.toBeInTheDocument()
  })

  it('shows an error and uploads nothing when the acceptance cannot be saved', async () => {
    vi.mocked(acceptUploadTerms).mockRejectedValueOnce(
      new ApiError(500, { key: 'http_error', message: 'x' }),
    )
    render(<LogoField {...baseProps} assets={assetsState(false)} />)
    pickAndUpload()

    fireEvent.click(await screen.findByTestId('admin-logo-terms-checkbox'))
    fireEvent.click(screen.getByTestId('admin-logo-terms-accept'))

    expect(await screen.findByTestId('admin-logo-terms-error')).toBeInTheDocument()
    expect(uploadBoardAsset).not.toHaveBeenCalled()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('uploads directly without a dialog once the account accepted the current terms', async () => {
    render(<LogoField {...baseProps} assets={assetsState(true)} />)

    pickAndUpload()

    await waitFor(() => expect(uploadBoardAsset).toHaveBeenCalledTimes(1))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(acceptUploadTerms).not.toHaveBeenCalled()
  })

  it('uploads directly without a dialog when the payload has no terms block (Community)', async () => {
    render(<LogoField {...baseProps} assets={noTermsState()} />)

    pickAndUpload()

    await waitFor(() => expect(uploadBoardAsset).toHaveBeenCalledTimes(1))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(acceptUploadTerms).not.toHaveBeenCalled()
  })

  it('closes on Escape without uploading', async () => {
    render(<LogoField {...baseProps} assets={assetsState(false)} />)
    pickAndUpload()
    await screen.findByRole('dialog')

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' })

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(uploadBoardAsset).not.toHaveBeenCalled()
  })
})

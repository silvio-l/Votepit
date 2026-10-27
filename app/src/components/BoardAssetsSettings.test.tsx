/**
 * Board imagery and the back link / footer note (P-28, P-31 Lite), as the
 * design editor mounts them: the image slots save at once, the link fields
 * join the editor's single save. Covers plan locks, upload, delete, and
 * server errors reaching the user.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useEffect, useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  type BoardAssetsResponse,
  type BoardLinkFooterInput,
  deleteBoardAsset,
  getBoardAssets,
  saveBoardLinkFooter,
  uploadBoardAsset,
} from '../lib/api/boardAssets'
import { ApiError } from '../lib/api/client'
import { BoardImageFields, BoardLinkFooterFields, useBoardAssets } from './BoardAssetsSettings'

vi.mock('../lib/api/boardAssets', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api/boardAssets')>()
  return {
    ...actual,
    getBoardAssets: vi.fn(),
    uploadBoardAsset: vi.fn(),
    deleteBoardAsset: vi.fn(),
    saveBoardLinkFooter: vi.fn(),
  }
})

function response(overrides: Partial<BoardAssetsResponse> = {}): BoardAssetsResponse {
  return {
    variants: {},
    allowed: { cover_upload: true, favicon_og: true, back_link_footer: true },
    max_upload_bytes: 4 * 1024 * 1024,
    link: { back_link_url: '', back_link_label: '', footer_note: '' },
    // Upload clause already accepted; the consent dialog has its own suite (UploadTermsGate.test.tsx).
    upload_terms: { version: '2026-10-draft', accepted: true },
    ...overrides,
  }
}

/** Mirrors the editor: one assets state, the link values lifted into the harness. */
function Harness({ onSave }: { onSave?: (link: BoardLinkFooterInput) => void }) {
  const assets = useBoardAssets('demo')
  const [link, setLink] = useState<BoardLinkFooterInput>({
    back_link_url: '',
    back_link_label: '',
    footer_note: '',
  })
  const [synced, setSynced] = useState(false)
  useEffect(() => {
    if (assets.data === null) return
    setLink(assets.data.link)
    setSynced(true)
  }, [assets.data])
  const locked = assets.data !== null && !assets.data.allowed.back_link_footer
  return (
    <>
      <BoardImageFields boardSlug="demo" assets={assets} disabled={false} />
      {synced && (
        <BoardLinkFooterFields
          value={link}
          onChange={setLink}
          errors={{}}
          locked={locked}
          disabled={false}
        />
      )}
      <button type="button" data-testid="harness-link-save" onClick={() => onSave?.(link)}>
        save
      </button>
    </>
  )
}

beforeEach(() => {
  URL.createObjectURL = vi.fn(() => 'blob:preview')
  URL.revokeObjectURL = vi.fn()
  vi.mocked(getBoardAssets).mockResolvedValue(response())
  vi.mocked(uploadBoardAsset).mockResolvedValue({ ok: true, variants: {} })
  vi.mocked(deleteBoardAsset).mockResolvedValue({ ok: true })
  vi.mocked(saveBoardLinkFooter).mockResolvedValue({ ok: true })
})

afterEach(() => {
  vi.clearAllMocks()
})

describe('BoardImageFields and BoardLinkFooterFields', () => {
  it('enables upload only after a file is chosen', async () => {
    render(<Harness />)
    const upload = await screen.findByTestId('admin-board-asset-cover-upload')
    expect(upload).toBeDisabled()

    const file = new File(['x'], 'cover.png', { type: 'image/png' })
    fireEvent.change(screen.getByTestId('admin-board-asset-cover-input'), {
      target: { files: [file] },
    })

    expect(screen.getByTestId('admin-board-asset-cover-upload')).toBeEnabled()
    fireEvent.click(screen.getByTestId('admin-board-asset-cover-upload'))
    await waitFor(() => expect(uploadBoardAsset).toHaveBeenCalledWith('demo', 'cover', file))
  })

  it('states that uploads save immediately', async () => {
    render(<Harness />)
    expect(await screen.findByTestId('admin-board-images-note')).toBeInTheDocument()
  })

  it('shows the locked notice instead of the cover input when the plan does not include it', async () => {
    vi.mocked(getBoardAssets).mockResolvedValue(
      response({ allowed: { cover_upload: false, favicon_og: true, back_link_footer: true } }),
    )
    render(<Harness />)

    expect(await screen.findByTestId('admin-board-asset-cover-locked')).toBeInTheDocument()
    expect(screen.queryByTestId('admin-board-asset-cover-input')).not.toBeInTheDocument()
    // The favicon slot is governed by its own gate and stays usable.
    expect(screen.getByTestId('admin-board-asset-favicon-input')).toBeInTheDocument()
  })

  it('disables the back link and footer fields on a locked plan', async () => {
    vi.mocked(getBoardAssets).mockResolvedValue(
      response({ allowed: { cover_upload: true, favicon_og: true, back_link_footer: false } }),
    )
    render(<Harness />)

    expect(await screen.findByTestId('admin-board-link-locked')).toBeInTheDocument()
    expect(screen.getByTestId('admin-board-link-url')).toBeDisabled()
    expect(screen.getByTestId('admin-board-footer-note')).toBeDisabled()
  })

  it('previews the stored rendition and removes it on request', async () => {
    vi.mocked(getBoardAssets).mockResolvedValue(
      response({ variants: { favicon: { id: 'a'.repeat(32), width: 64, height: 64 } } }),
    )
    render(<Harness />)

    const preview = await screen.findByTestId('admin-board-asset-favicon-preview')
    expect(preview.getAttribute('src')).toContain(`/demo/assets/${'a'.repeat(32)}`)
    fireEvent.click(screen.getByTestId('admin-board-asset-favicon-remove'))

    await waitFor(() => expect(deleteBoardAsset).toHaveBeenCalledWith('demo', 'favicon'))
  })

  it('hands the entered link values to the editor save', async () => {
    const onSave = vi.fn()
    render(<Harness onSave={onSave} />)
    await screen.findByTestId('admin-board-link-url')

    fireEvent.change(screen.getByTestId('admin-board-link-url'), {
      target: { value: 'https://example.com/help' },
    })
    fireEvent.change(screen.getByTestId('admin-board-link-label'), { target: { value: 'Hilfe' } })
    fireEvent.change(screen.getByTestId('admin-board-footer-note'), {
      target: { value: 'Betrieben von Acme' },
    })
    fireEvent.click(screen.getByTestId('harness-link-save'))

    expect(onSave).toHaveBeenLastCalledWith({
      back_link_url: 'https://example.com/help',
      back_link_label: 'Hilfe',
      footer_note: 'Betrieben von Acme',
    })
  })

  it('tells the admin when the server refuses the image as plan-locked', async () => {
    vi.mocked(uploadBoardAsset).mockRejectedValue(
      new ApiError(403, { key: 'plan_feature_locked', message: 'locked' }),
    )
    render(<Harness />)
    await screen.findByTestId('admin-board-asset-cover-input')

    const file = new File(['x'], 'cover.png', { type: 'image/png' })
    fireEvent.change(screen.getByTestId('admin-board-asset-cover-input'), {
      target: { files: [file] },
    })
    fireEvent.click(screen.getByTestId('admin-board-asset-cover-upload'))

    expect(await screen.findByTestId('admin-board-asset-cover-error')).toHaveTextContent(
      'Eigene Bilder sind im aktuellen Tarif nicht enthalten.',
    )
  })
})

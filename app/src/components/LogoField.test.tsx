/**
 * Unit tests for LogoField (P-28b): the upload gate per plan, client-side file checks,
 * the upload call, and the legacy link that stays visible until an upload exists.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { uploadBoardAsset } from '../lib/api/boardAssets'
import type { BoardAssetsState } from './BoardAssetsSettings'
import { LogoField } from './LogoField'

vi.mock('../lib/api/boardAssets', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api/boardAssets')>()
  return { ...actual, uploadBoardAsset: vi.fn(() => Promise.resolve()), deleteBoardAsset: vi.fn() }
})

const assetsState = (overrides: Partial<BoardAssetsState> = {}): BoardAssetsState => ({
  data: null,
  loadError: false,
  reload: vi.fn(() => Promise.resolve()),
  ...overrides,
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

const pngFile = (size = 100) => new File([new Uint8Array(size)], 'logo.png', { type: 'image/png' })

describe('LogoField', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  beforeAll(() => {
    URL.createObjectURL = vi.fn(() => 'blob:preview')
    URL.revokeObjectURL = vi.fn()
  })

  it('shows the upgrade notice and no input on a plan without logo upload', () => {
    render(<LogoField {...baseProps} assets={assetsState()} allowed={false} requiredPlan="lite" />)

    expect(screen.getByTestId('admin-logo-locked')).toBeInTheDocument()
    expect(screen.queryByTestId('admin-logo-input')).not.toBeInTheDocument()
  })

  it('rejects an SVG before any upload call', () => {
    render(<LogoField {...baseProps} assets={assetsState()} />)

    const svg = new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' })
    fireEvent.change(screen.getByTestId('admin-logo-input'), { target: { files: [svg] } })

    expect(screen.getByTestId('admin-logo-error')).toBeInTheDocument()
    expect(uploadBoardAsset).not.toHaveBeenCalled()
  })

  it('rejects a file above the server limit before any upload call', () => {
    render(<LogoField {...baseProps} assets={assetsState()} maxBytes={50} />)

    fireEvent.change(screen.getByTestId('admin-logo-input'), {
      target: { files: [pngFile(100)] },
    })

    expect(screen.getByTestId('admin-logo-error')).toBeInTheDocument()
    expect(uploadBoardAsset).not.toHaveBeenCalled()
  })

  it('uploads the picked logo and reloads the assets', async () => {
    const reload = vi.fn(() => Promise.resolve())
    const accepted = {
      data: {
        variants: {},
        allowed: { cover_upload: true, favicon_og: true, back_link_footer: true, logo_url: true },
        max_upload_bytes: 2 * 1024 * 1024,
        link: { back_link_url: '', back_link_label: '', footer_note: '' },
        upload_terms: { version: '2026-10-draft', accepted: true },
      },
      loadError: false,
      reload,
    }
    render(<LogoField {...baseProps} assets={accepted} />)

    const file = pngFile()
    fireEvent.change(screen.getByTestId('admin-logo-input'), { target: { files: [file] } })
    expect(screen.getByTestId('admin-logo-pending')).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('admin-logo-upload'))

    await waitFor(() => expect(uploadBoardAsset).toHaveBeenCalledWith('demo', 'logo', file))
    await waitFor(() => expect(reload).toHaveBeenCalled())
  })

  it('shows the legacy link preview only while no upload exists', () => {
    const { rerender } = render(
      <LogoField {...baseProps} legacyUrl="https://example.com/logo.png" assets={assetsState()} />,
    )
    expect(screen.getByTestId('admin-logo-legacy-preview')).toBeInTheDocument()

    rerender(
      <LogoField
        {...baseProps}
        legacyUrl="https://example.com/logo.png"
        assets={assetsState({
          data: {
            variants: { logo: { id: 'abc123', width: 256, height: 256 } },
          } as unknown as BoardAssetsState['data'],
        })}
      />,
    )
    expect(screen.queryByTestId('admin-logo-legacy-preview')).not.toBeInTheDocument()
    expect(screen.getByTestId('admin-logo-preview')).toBeInTheDocument()
  })

  it('uploads into the wordmark slot and explains the plate when the bar needs one', () => {
    const stored = {
      variants: { wordmark: { id: 'a'.repeat(32), width: 1200, height: 300, ink: 0.01 } },
    } as unknown as BoardAssetsState['data']
    render(
      <LogoField
        {...baseProps}
        testId="admin-wordmark"
        kind="wordmark"
        barColor="#102F28"
        assets={assetsState({ data: stored })}
      />,
    )

    expect(screen.getByTestId('admin-wordmark-plate')).toBeInTheDocument()
    expect(screen.getByTestId('admin-wordmark-plate-tip')).toBeInTheDocument()

    fireEvent.change(screen.getByTestId('admin-wordmark-input'), { target: { files: [pngFile()] } })
    fireEvent.click(screen.getByTestId('admin-wordmark-upload'))
    return waitFor(() =>
      expect(uploadBoardAsset).toHaveBeenCalledWith('demo', 'wordmark', expect.any(File)),
    )
  })

  it('shows no empty-state text while a new file is picked', () => {
    render(<LogoField {...baseProps} assets={assetsState()} />)

    fireEvent.change(screen.getByTestId('admin-logo-input'), { target: { files: [pngFile()] } })

    expect(screen.queryByTestId('admin-logo-empty')).not.toBeInTheDocument()
    expect(screen.getByTestId('admin-logo-pending')).toBeInTheDocument()
  })
})

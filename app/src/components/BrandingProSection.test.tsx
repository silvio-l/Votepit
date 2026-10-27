/**
 * Pro branding groups (P-29, P-30, P-31) as the design editor mounts them:
 * presets, wording and header links share one state. Covers the per-group
 * plan gates (a locked group is disabled and keeps its stored values), the
 * save payload, and the plan-lock and validation errors.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  type BoardBrandingResponse,
  getBoardBranding,
  saveBoardBranding,
} from '../lib/api/boardBranding'
import { ApiError } from '../lib/api/client'
import {
  type BrandingProSave,
  ProLinkFields,
  ProPresetFields,
  ProWordingFields,
  useBrandingPro,
} from './BrandingProSection'

vi.mock('../lib/api/boardBranding', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api/boardBranding')>()
  return { ...actual, getBoardBranding: vi.fn(), saveBoardBranding: vi.fn() }
})

const ALLOWED = { allowed: true as const, required_plan: null }
const LOCKED = { allowed: false as const, required_plan: 'pro' }

function response(overrides: Partial<BoardBrandingResponse> = {}): BoardBrandingResponse {
  return {
    board_slug: 'demo',
    board_name: 'Demo',
    frozen_at: null,
    typography_preset: 'default',
    shape_style: 'rounded',
    density: 'comfortable',
    wording: {},
    header_links: [],
    roadmap_hidden: false,
    applied: {
      typography: 'default',
      shape: 'rounded',
      density: 'comfortable',
      wording: false,
      header_links: false,
      roadmap_hidden: false,
    },
    entitlements: {
      current_plan: 'lite',
      can_change_plan: true,
      features: {
        typography_presets: ALLOWED,
        custom_wording: ALLOWED,
        header_links_sections: ALLOWED,
      } as never,
    },
    ...overrides,
  }
}

function withFeatures(features: Record<string, unknown>): BoardBrandingResponse {
  const base = response()
  return {
    ...base,
    entitlements: { current_plan: 'lite', can_change_plan: true, features: features as never },
  }
}

/** Mirrors the editor: one hook state, all three groups, one save trigger. */
function Harness() {
  const pro = useBrandingPro('demo')
  const [result, setResult] = useState<BrandingProSave | null>(null)
  return (
    <>
      <ProPresetFields pro={pro} disabled={false} />
      <ProWordingFields pro={pro} disabled={false} />
      <ProLinkFields pro={pro} disabled={false} />
      <button
        type="button"
        data-testid="harness-pro-save"
        onClick={() => void pro.save().then(setResult)}
      >
        save
      </button>
      {result !== null && !result.ok && <p data-testid="harness-pro-error">{result.message}</p>}
    </>
  )
}

beforeEach(() => {
  vi.mocked(saveBoardBranding).mockResolvedValue({ ok: true })
})

afterEach(() => {
  vi.clearAllMocks()
})

describe('Pro branding groups', () => {
  it('disables a locked group and offers the upgrade, keeping the other groups editable', async () => {
    vi.mocked(getBoardBranding).mockResolvedValue(
      withFeatures({
        typography_presets: LOCKED,
        custom_wording: ALLOWED,
        header_links_sections: ALLOWED,
      }),
    )
    render(<Harness />)

    await screen.findByTestId('branding-pro-presets-locked')
    expect((screen.getByTestId('branding-pro-typography') as HTMLSelectElement).disabled).toBe(true)
    expect(
      (screen.getByTestId('branding-pro-wording-thank_you') as HTMLInputElement).disabled,
    ).toBe(false)
    expect(screen.queryByTestId('branding-pro-wording-locked')).toBeNull()
  })

  it('saves the whole set, including the stored values of a locked group', async () => {
    vi.mocked(getBoardBranding).mockResolvedValue(
      withFeatures({
        typography_presets: LOCKED,
        custom_wording: ALLOWED,
        header_links_sections: ALLOWED,
      }),
    )
    render(<Harness />)
    const thankYou = await screen.findByTestId('branding-pro-wording-thank_you')
    fireEvent.change(thankYou, { target: { value: 'Danke!' } })
    fireEvent.click(screen.getByTestId('harness-pro-save'))

    await waitFor(() => expect(saveBoardBranding).toHaveBeenCalledTimes(1))
    const [slug, input] = vi.mocked(saveBoardBranding).mock.calls[0] ?? []
    expect(slug).toBe('demo')
    expect(input?.typography_preset).toBe('default')
    expect(input?.wording).toEqual({ de: { thank_you: 'Danke!' } })
    expect(input?.header_links).toHaveLength(5)
    expect(input?.roadmap_hidden).toBe(false)
  })

  it('shows the plan-lock message when the server refuses a locked change', async () => {
    vi.mocked(getBoardBranding).mockResolvedValue(response())
    vi.mocked(saveBoardBranding).mockRejectedValue(
      new ApiError(422, {
        key: 'plan_locked_field',
        message: 'Validation failed.',
        fields: {},
      } as never),
    )
    render(<Harness />)
    fireEvent.click(await screen.findByTestId('harness-pro-save'))

    expect(await screen.findByTestId('harness-pro-error')).toHaveTextContent(
      'Dieser Bereich ist im aktuellen Tarif nicht enthalten.',
    )
  })

  it('shows a load error instead of the form when the settings cannot be read', async () => {
    vi.mocked(getBoardBranding).mockRejectedValue(new Error('network'))
    render(<Harness />)

    // Each Pro group reports the failed load in its own panel.
    expect((await screen.findAllByTestId('branding-pro-load-error')).length).toBeGreaterThan(0)
    expect(screen.queryByTestId('branding-pro-typography')).toBeNull()
  })
})

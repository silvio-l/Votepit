/**
 * Pro board branding (migration 0093): read and save the presets, the custom
 * wording and the header links of one board. Plan gates are enforced by the
 * server; the entitlements returned here only steer which inputs are offered.
 */

import { accountPath } from '../accountContext'
import type {
  BrandingPro,
  DensityPreset,
  ShapeStyle,
  TypographyPreset,
  WordingField,
} from '../branding'
import type { Entitlements } from '../entitlements'
import { request } from './client'

export interface BoardBrandingResponse {
  board_slug: string
  board_name: string
  frozen_at: string | null
  typography_preset: TypographyPreset
  shape_style: ShapeStyle
  density: DensityPreset
  wording: BrandingPro['wording']
  header_links: Array<{ url: string; label: string }>
  roadmap_hidden: boolean
  /** What the public page applies right now (kept values are not included). */
  applied: {
    typography: TypographyPreset
    shape: ShapeStyle
    density: DensityPreset
    wording: boolean
    header_links: boolean
    roadmap_hidden: boolean
  }
  entitlements: Entitlements | null
}

export interface BoardBrandingInput {
  typography_preset: TypographyPreset
  shape_style: ShapeStyle
  density: DensityPreset
  wording: BrandingPro['wording']
  header_links: Array<{ url: string; label: string }>
  roadmap_hidden: boolean
}

/** GET /admin/boards/{slug}/branding/pro — stored values, applied values, entitlements. */
export function getBoardBranding(boardSlug: string): Promise<BoardBrandingResponse> {
  return request<BoardBrandingResponse>(
    'GET',
    accountPath(`/admin/boards/${boardSlug}/branding/pro`),
  )
}

/** POST /admin/boards/{slug}/branding/pro — saves the whole set; errors carry `fields` and `locked_fields`. */
export function saveBoardBranding(
  boardSlug: string,
  input: BoardBrandingInput,
): Promise<{ ok: true }> {
  return request<{ ok: true }>(
    'POST',
    accountPath(`/admin/boards/${boardSlug}/branding/pro`),
    input,
  )
}

/** The wording fields the settings offer. */
export const EDITABLE_WORDING_FIELDS: WordingField[] = [
  'idea_singular',
  'idea_plural',
  'vote_for',
  'vote_against',
  'submit_button',
  'empty_state',
  'thank_you',
]

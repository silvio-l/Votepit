/**
 * Behaviour tests for the full-screen BrandingBuilder: the page and device
 * switches, the unsaved-changes status and discard, Escape with unsaved
 * changes, and area buttons that open and focus their settings field.
 */

import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import {
  BrandingBuilder,
  type BrandingBuilderPlan,
  type BrandingBuilderValues,
} from './BrandingBuilder'

const values: BrandingBuilderValues = {
  primary: '#ff0000',
  secondary: '',
  logo: '',
  intro: '',
  hideBadge: false,
}
const plan: BrandingBuilderPlan = {
  secondaryAllowed: true,
  logoAllowed: true,
  introAllowed: true,
  badgeHideAllowed: true,
  secondaryRequiredPlan: null,
  logoRequiredPlan: null,
}

function renderBuilder(overrides: Partial<Parameters<typeof BrandingBuilder>[0]> = {}) {
  const props = {
    boardName: 'Acme Roadmap',
    values,
    plan,
    dirty: false,
    saving: false,
    frozen: false,
    onSave: vi.fn(),
    onDiscard: vi.fn(),
    onBack: vi.fn(),
    onSubmit: vi.fn((e: { preventDefault: () => void }) => e.preventDefault()),
    groups: [
      {
        id: 'colors' as const,
        icon: null,
        label: 'Farben',
        badge: null,
        content: <input id="admin-primary-color" data-testid="field-primary" />,
      },
      {
        id: 'advanced' as const,
        icon: null,
        label: 'Erweitert',
        badge: 'pro' as const,
        content: <input id="admin-hide-badge" data-testid="field-badge" />,
      },
    ],
    ...overrides,
  }
  render(<BrandingBuilder {...props} />)
  return props
}

describe('BrandingBuilder', () => {
  it('offers the settings and preview tabs below the desktop breakpoint', () => {
    renderBuilder()
    expect(screen.getByTestId('branding-builder-tab-settings')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    fireEvent.click(screen.getByTestId('branding-builder-tab-preview'))
    expect(screen.getByTestId('branding-builder-tab-preview')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  it('switches the canvas page and device', () => {
    renderBuilder()
    expect(screen.getByTestId('branding-editor-page-board')).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(screen.getByTestId('branding-editor-page-idea'))
    expect(screen.getByTestId('branding-editor-page-idea')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('branding-editor-page-board')).toHaveAttribute(
      'aria-pressed',
      'false',
    )

    fireEvent.click(screen.getByTestId('branding-editor-device-tablet'))
    expect(screen.getByTestId('branding-editor-device-tablet')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(screen.getByTestId('branding-editor-device')).toHaveAttribute('data-device', 'tablet')
  })

  it('shows the board address in the desktop browser bar without the scheme', () => {
    renderBuilder({ boardUrl: 'https://app.example.com/acme/feedback' })
    expect(screen.getByTestId('branding-builder-address')).toHaveTextContent(
      'app.example.com/acme/feedback',
    )
  })

  it('shows the unsaved status and wires discard and save only when dirty', () => {
    const { onDiscard, onSave } = renderBuilder({ dirty: true })
    expect(screen.getByTestId('branding-editor-status')).toHaveTextContent('Ungespeicherte')
    fireEvent.click(screen.getByTestId('branding-editor-discard'))
    fireEvent.click(screen.getByTestId('branding-editor-save'))
    expect(onDiscard).toHaveBeenCalledOnce()
    expect(onSave).toHaveBeenCalledOnce()
  })

  it('keeps save available while clean, but discard needs changes', () => {
    renderBuilder({ dirty: false })
    expect(screen.getByTestId('branding-editor-save')).toBeEnabled()
    expect(screen.getByTestId('branding-editor-discard')).toBeDisabled()
  })

  it('disables save and discard on a frozen board', () => {
    renderBuilder({ dirty: true, frozen: true })
    expect(screen.getByTestId('branding-editor-save')).toBeDisabled()
    expect(screen.getByTestId('branding-editor-discard')).toBeDisabled()
  })

  it('asks before leaving with unsaved changes and never leaves silently', () => {
    const { onBack } = renderBuilder({ dirty: true })
    fireEvent.click(screen.getByTestId('branding-editor-back'))
    expect(onBack).not.toHaveBeenCalled()
    expect(screen.getByTestId('branding-editor-leave')).toBeInTheDocument()
  })

  it('leaves at once when there is nothing to lose', () => {
    const { onBack } = renderBuilder({ dirty: false })
    fireEvent.click(screen.getByTestId('branding-editor-back'))
    expect(onBack).toHaveBeenCalledOnce()
  })

  it('opens the group that owns an area and focuses its field', () => {
    // jsdom does not implement scrollIntoView.
    Element.prototype.scrollIntoView = vi.fn()
    renderBuilder()
    fireEvent.click(screen.getByTestId('branding-builder-area-votes'))
    expect(screen.getByTestId('field-primary')).toHaveFocus()
    expect(screen.getByTestId('branding-builder-area-votes')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  it('shows the plan tier of a locked group', () => {
    renderBuilder()
    expect(screen.getByTestId('branding-editor-group-advanced-plan')).toBeInTheDocument()
  })
})

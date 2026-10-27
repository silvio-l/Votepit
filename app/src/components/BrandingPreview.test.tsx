/**
 * Unit tests for BrandingPreview — the live client-side branding mock-up in
 * AdminPage.tsx's branding form. Verifies it renders the given colors/logo,
 * falls back safely on invalid/unsafe input, and reacts to prop changes
 * (typing) without ever needing the API.
 *
 * Note: the <img> is decorative (`alt=""`), so it gets ARIA role
 * "presentation", not "img" — queried via `container.querySelector('img')`
 * instead of `getByRole('img')`.
 */

import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { BrandingPreview } from './BrandingPreview'

describe('BrandingPreview', () => {
  it('renders the board name and applies valid primary/secondary colors', () => {
    render(
      <BrandingPreview
        boardName="Acme Roadmap"
        primaryColor="#ff0000"
        secondaryColor="#00ff00"
        logoUrl=""
        secondaryAllowed
      />,
    )

    // The name shows in the top bar and in the row below it.
    expect(screen.getAllByText('Acme Roadmap').length).toBeGreaterThan(0)
    // The frame carries the label as an accessible name only; no visible label inside the device frame.
    expect(screen.queryByText('Vorschau')).not.toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Vorschau' })).toBeInTheDocument()
    // No logo set → initials placeholder, colored with the secondary color.
    const initials = screen.getByText('A')
    expect(initials).toHaveStyle({ backgroundColor: '#00ff00' })
    // No loose swatch dot in the title row: the real board does not show one.
    expect(screen.queryByText('Sekundärfarbe')).not.toBeInTheDocument()
  })

  it('falls back to the platform default accent for an invalid primary color', () => {
    render(
      <BrandingPreview
        boardName="Board"
        primaryColor="not-a-color"
        secondaryColor=""
        logoUrl=""
        secondaryAllowed
      />,
    )

    // The invalid value falls back to the default accent (#1fa890) on a bar that stays forest.
    const bar = screen.getByTestId('branding-preview-topbar')
    expect(bar.style.getPropertyValue('--vpds-c-topbar-accent')).toBe('#1FA890')
    expect(bar.style.getPropertyValue('--vpds-c-topbar-background')).toBe('')
  })

  it('paints the top bar with the secondary colour on a plan that has it', () => {
    render(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor="#0b6b4c"
        logoUrl=""
        secondaryAllowed
      />,
    )

    const bar = screen.getByTestId('branding-preview-topbar')
    expect(bar.style.getPropertyValue('--vpds-c-topbar-background')).toBe('#0B6B4C')
    expect(screen.queryByText(/Auf deinem aktuellen Plan/)).not.toBeInTheDocument()
  })

  it('keeps the forest bar and shows the upgrade hint on a plan without the secondary colour', () => {
    render(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor="#0b6b4c"
        logoUrl=""
        secondaryAllowed={false}
      />,
    )

    const bar = screen.getByTestId('branding-preview-topbar')
    expect(bar.style.getPropertyValue('--vpds-c-topbar-background')).toBe('')
    expect(bar.style.getPropertyValue('--vpds-c-topbar-accent')).toBe('#1FA890')
    expect(screen.getByText(/Auf deinem aktuellen Plan nicht verfügbar/)).toBeInTheDocument()
  })

  it('renders an <img> for a safe relative logo URL', () => {
    const { container } = render(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor=""
        logoUrl="/assets/logo.svg"
        secondaryAllowed
      />,
    )

    const img = container.querySelector('img')
    expect(img).not.toBeNull()
    expect(img?.getAttribute('src')).toBe('/assets/logo.svg')
  })

  it('falls back to initials for an unsafe logo URL scheme (defense in depth)', () => {
    const { container } = render(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor=""
        logoUrl="javascript:alert(1)"
        secondaryAllowed
      />,
    )

    expect(container.querySelector('img')).toBeNull()
    expect(screen.getByText('B')).toBeInTheDocument()
  })

  it('falls back to initials for a protocol-relative logo URL', () => {
    const { container } = render(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor=""
        logoUrl="//evil.example/logo.png"
        secondaryAllowed
      />,
    )

    expect(container.querySelector('img')).toBeNull()
  })

  it('falls back to a placeholder name and initial when the board name is blank', () => {
    render(
      <BrandingPreview
        boardName=""
        primaryColor="#1fa890"
        secondaryColor=""
        logoUrl=""
        secondaryAllowed
      />,
    )

    // Default test language is German (see lib/i18n/context.tsx DEFAULT_LANGUAGE).
    expect(screen.getAllByText('Dein Board').length).toBeGreaterThan(0)
    expect(screen.getByText('D')).toBeInTheDocument()
  })

  it('reverts to the initials placeholder when a previously-safe logo URL is edited to become empty', () => {
    const { container, rerender } = render(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor=""
        logoUrl="/assets/logo.svg"
        secondaryAllowed
      />,
    )
    expect(container.querySelector('img')).not.toBeNull()

    rerender(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor=""
        logoUrl=""
        secondaryAllowed
      />,
    )
    expect(container.querySelector('img')).toBeNull()
    expect(screen.getByText('B')).toBeInTheDocument()
  })
})

describe('BrandingPreview language', () => {
  it('renders its texts in the edited wording locale when one is given', () => {
    render(
      <BrandingPreview
        boardName="Acme"
        primaryColor="#ff0000"
        secondaryColor=""
        logoUrl=""
        secondaryAllowed
        previewLanguage="en"
      />,
    )

    expect(screen.getByRole('group', { name: 'Preview' })).toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Vorschau' })).not.toBeInTheDocument()
  })

  it('keeps the page language when no locale is given', () => {
    render(
      <BrandingPreview
        boardName="Acme"
        primaryColor=""
        secondaryColor=""
        logoUrl=""
        secondaryAllowed
      />,
    )

    expect(screen.getByRole('group', { name: 'Vorschau' })).toBeInTheDocument()
  })
})

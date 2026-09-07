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
      />,
    )

    expect(screen.getByText('Acme Roadmap')).toBeInTheDocument()
    // A visible "Vorschau"/"Preview" label, not just an aria-label.
    expect(screen.getByText('Vorschau')).toBeInTheDocument()
    // No logo set → initials placeholder, colored with the secondary color.
    const initials = screen.getByText('A')
    expect(initials).toHaveStyle({ backgroundColor: '#00ff00' })
    // Secondary color also renders as its own visible, labeled swatch.
    const secondarySwatch = screen.getByText('Sekundärfarbe')
    expect(secondarySwatch).toHaveStyle({ backgroundColor: '#00ff00' })
  })

  it('falls back to the platform default color for an invalid primary color', () => {
    render(
      <BrandingPreview boardName="Board" primaryColor="not-a-color" secondaryColor="" logoUrl="" />,
    )

    const group = screen.getByRole('group')
    const stripe = group.querySelector(':scope > div')
    expect(stripe).toHaveStyle({ backgroundColor: '#1fa890' })
  })

  it('renders an <img> for a safe relative logo URL', () => {
    const { container } = render(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor=""
        logoUrl="/assets/logo.svg"
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
      />,
    )

    expect(container.querySelector('img')).toBeNull()
  })

  it('falls back to a placeholder name and initial when the board name is blank', () => {
    render(<BrandingPreview boardName="" primaryColor="#1fa890" secondaryColor="" logoUrl="" />)

    // Default test language is German (see lib/i18n/context.tsx DEFAULT_LANGUAGE).
    expect(screen.getByText('Dein Board')).toBeInTheDocument()
    expect(screen.getByText('D')).toBeInTheDocument()
  })

  it('reverts to the initials placeholder when a previously-safe logo URL is edited to become empty', () => {
    const { container, rerender } = render(
      <BrandingPreview
        boardName="Board"
        primaryColor="#1fa890"
        secondaryColor=""
        logoUrl="/assets/logo.svg"
      />,
    )
    expect(container.querySelector('img')).not.toBeNull()

    rerender(
      <BrandingPreview boardName="Board" primaryColor="#1fa890" secondaryColor="" logoUrl="" />,
    )
    expect(container.querySelector('img')).toBeNull()
    expect(screen.getByText('B')).toBeInTheDocument()
  })
})

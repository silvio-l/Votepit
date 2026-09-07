/**
 * Unit tests for Avatar — renders either the real image or the neutral
 * silhouette placeholder, never a third-party (gravatar-style) fallback.
 */

import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Avatar } from './Avatar'

describe('Avatar', () => {
  it('renders an <img> with the given src when avatarUrl is set', () => {
    render(<Avatar avatarUrl="/avatar/abc123.jpg" alt="test" />)
    const img = screen.getByRole('img', { name: 'test' })
    expect(img.tagName).toBe('IMG')
    expect(img).toHaveAttribute('src', '/avatar/abc123.jpg')
  })

  it('renders the silhouette placeholder (no <img>) when avatarUrl is null', () => {
    render(<Avatar avatarUrl={null} alt="placeholder" />)
    const placeholder = screen.getByRole('img', { name: 'placeholder' })
    expect(placeholder.tagName).not.toBe('IMG')
    // Never falls back to any third-party gravatar-style URL.
    expect(placeholder.querySelector('img')).toBeNull()
  })

  it('applies the requested size to both image and placeholder variants', () => {
    const { rerender } = render(<Avatar avatarUrl="/avatar/abc123.jpg" size={64} alt="a" />)
    expect(screen.getByRole('img', { name: 'a' })).toHaveStyle({ width: '64px', height: '64px' })

    rerender(<Avatar avatarUrl={null} size={64} alt="a" />)
    expect(screen.getByRole('img', { name: 'a' })).toHaveStyle({ width: '64px', height: '64px' })
  })
})

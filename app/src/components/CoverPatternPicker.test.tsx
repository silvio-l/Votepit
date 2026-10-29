/**
 * Unit tests for CoverPatternPicker (P-28c): selecting a pattern on an allowed plan,
 * and the locked preview on plans without the cover_pattern feature.
 */

import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { CoverPatternPicker } from './CoverPatternPicker'

describe('CoverPatternPicker', () => {
  it('lays tiles out in an auto-fill grid and lets long labels wrap instead of overflowing', () => {
    render(<CoverPatternPicker testId="cover-pattern" value={null} onChange={vi.fn()} allowed />)

    const group = screen.getByRole('radiogroup')
    expect(group.className).toContain('repeat(auto-fill,minmax(7rem,1fr))')
    expect(group.className).not.toMatch(/grid-cols-\d/)

    const option = screen.getByTestId('cover-pattern-option-grid')
    expect(option.className).toContain('min-w-0')
    const label = option.querySelector('span:last-child')
    expect(label?.className).toContain('break-words')
    expect(label?.className).toContain('min-w-0')
  })

  it('reports the chosen pattern and the default cover as null', () => {
    const onChange = vi.fn()
    render(<CoverPatternPicker testId="cover-pattern" value="waves" onChange={onChange} allowed />)

    fireEvent.click(screen.getByTestId('cover-pattern-option-dots'))
    expect(onChange).toHaveBeenLastCalledWith('dots')

    fireEvent.click(screen.getByTestId('cover-pattern-option-default'))
    expect(onChange).toHaveBeenLastCalledWith(null)
  })

  it('marks the stored pattern as checked', () => {
    render(<CoverPatternPicker testId="cover-pattern" value="grid" onChange={vi.fn()} allowed />)

    expect(screen.getByTestId('cover-pattern-option-grid')).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByTestId('cover-pattern-option-dots')).toHaveAttribute('aria-checked', 'false')
  })

  it('disables every option on a plan without the feature and never calls onChange', () => {
    const onChange = vi.fn()
    render(
      <CoverPatternPicker
        testId="cover-pattern"
        value={null}
        onChange={onChange}
        allowed={false}
        requiredPlan="starter"
      />,
    )

    const option = screen.getByTestId('cover-pattern-option-waves')
    expect(option).toBeDisabled()
    fireEvent.click(option)
    expect(onChange).not.toHaveBeenCalled()
  })
})

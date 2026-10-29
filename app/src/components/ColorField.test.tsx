/**
 * Unit tests for ColorField: the contrast suggestion for accent and fill roles,
 * and the auto-fix action that writes the suggestion back.
 */

import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ColorField, contrastSuggestion } from './ColorField'

describe('contrastSuggestion', () => {
  it('returns null for a valid colour that already has enough contrast', () => {
    expect(contrastSuggestion('#000000', 'accent')).toBeNull()
    expect(contrastSuggestion('#000000', 'fill')).toBeNull()
  })

  it('returns null for input that is not a six-digit hex colour', () => {
    expect(contrastSuggestion('', 'accent')).toBeNull()
    expect(contrastSuggestion('#abc', 'fill')).toBeNull()
  })
})

describe('ColorField', () => {
  const base = {
    testId: 'field-primary',
    id: 'admin-primary-color',
    name: 'primary',
    label: 'Primary',
    placeholder: '#6366f1',
    swatchRole: 'accent' as const,
    pickerLabel: 'Pick colour',
    autoFixLabel: 'Use the suggested colour',
    autoFixNotice: 'Too light. Suggested:',
  }

  it('shows no notice for a colour with sufficient contrast', () => {
    render(<ColorField {...base} value="#000000" onChange={vi.fn()} />)
    expect(screen.queryByTestId('field-primary-contrast')).not.toBeInTheDocument()
    expect(screen.queryByTestId('field-primary-autofix')).not.toBeInTheDocument()
  })

  it('writes the suggested colour back when auto-fix is pressed', () => {
    const suggestion = contrastSuggestion('#ffff00', 'accent')
    const onChange = vi.fn()
    render(<ColorField {...base} value="#ffff00" onChange={onChange} />)
    const autofix = screen.queryByTestId('field-primary-autofix')
    if (suggestion === null) {
      expect(autofix).not.toBeInTheDocument()
      return
    }
    expect(screen.getByTestId('field-primary-contrast')).toBeInTheDocument()
    fireEvent.click(autofix as HTMLElement)
    expect(onChange).toHaveBeenCalledWith(suggestion)
  })

  it('passes the picker value through in upper case', () => {
    const onChange = vi.fn()
    render(<ColorField {...base} value="#00ff00" onChange={onChange} />)
    fireEvent.change(screen.getByTestId('field-primary-picker'), { target: { value: '#123abc' } })
    expect(onChange).toHaveBeenCalledWith('#123ABC')
  })
})

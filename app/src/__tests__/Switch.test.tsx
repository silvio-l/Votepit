/**
 * RTL tests for the @votepit/ui Switch — semantics only: a real
 * role="switch" button whose aria-checked mirrors `checked`, a label that
 * targets it, and an onChange that reports the flipped value.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Switch } from '@votepit/ui'
import { describe, expect, it, vi } from 'vitest'

describe('Switch', () => {
  it('exposes role="switch" with aria-checked and is reachable via its label', () => {
    render(<Switch label="Visible" hint="What it means" checked={false} onChange={() => {}} />)

    const toggle = screen.getByRole('switch', { name: 'Visible' })
    expect(toggle).toHaveAttribute('aria-checked', 'false')
    expect(toggle).toHaveAccessibleDescription('What it means')
    expect(screen.getByLabelText('Visible')).toBe(toggle)
  })

  it('reports the flipped value on click and on keyboard activation', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    render(<Switch label="Visible" checked={false} onChange={onChange} />)

    await user.click(screen.getByRole('switch'))
    expect(onChange).toHaveBeenLastCalledWith(true)

    screen.getByRole('switch').focus()
    await user.keyboard('[Space]')
    expect(onChange).toHaveBeenCalledTimes(2)
  })

  it('does not fire when disabled', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    render(<Switch label="Visible" checked={true} onChange={onChange} disabled />)

    const toggle = screen.getByRole('switch')
    expect(toggle).toBeDisabled()
    expect(toggle).toHaveAttribute('aria-checked', 'true')
    await user.click(toggle)
    expect(onChange).not.toHaveBeenCalled()
  })
})

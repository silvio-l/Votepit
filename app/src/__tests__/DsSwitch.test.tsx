/**
 * RTL tests for the DS Switch: a real role="switch" button whose aria-checked
 * mirrors `checked`, a label that names it, an optional hint, and an onChange
 * that reports the flipped value. Disabled switches do not fire.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Switch } from '@votepit/ui/ds'
import { describe, expect, it, vi } from 'vitest'

describe('DS Switch', () => {
  it('exposes role="switch" with aria-checked, named by its label, described by its hint', () => {
    render(
      <Switch
        label="Visible"
        hint="What it means"
        checked={false}
        onChange={() => {}}
        testId="sw-visible"
      />,
    )

    const toggle = screen.getByRole('switch', { name: 'Visible' })
    expect(toggle).toHaveAttribute('aria-checked', 'false')
    expect(toggle).toHaveAccessibleDescription('What it means')
    expect(toggle).toBe(screen.getByTestId('sw-visible'))
  })

  it('reports the flipped value on click and on keyboard activation', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    render(<Switch label="Visible" checked={false} onChange={onChange} testId="sw-visible" />)

    await user.click(screen.getByRole('switch'))
    expect(onChange).toHaveBeenLastCalledWith(true)

    screen.getByRole('switch').focus()
    await user.keyboard('[Space]')
    expect(onChange).toHaveBeenCalledTimes(2)
  })

  it('reflects checked=true in aria-checked', () => {
    render(<Switch label="On" checked onChange={() => {}} testId="sw-on" />)
    expect(screen.getByRole('switch', { name: 'On' })).toHaveAttribute('aria-checked', 'true')
  })

  it('does not fire when disabled', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    render(<Switch label="Visible" checked onChange={onChange} disabled testId="sw-visible" />)

    const toggle = screen.getByRole('switch')
    expect(toggle).toBeDisabled()
    await user.click(toggle)
    expect(onChange).not.toHaveBeenCalled()
  })
})

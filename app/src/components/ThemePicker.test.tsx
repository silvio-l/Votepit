/**
 * ThemePicker — the app-bar colour-scheme menu: the trigger names the current
 * choice, the menu offers System / Light / Dark and stores the pick.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { setThemePref } from '../lib/theme'
import { ThemePicker } from './ThemePicker'

afterEach(() => {
  setThemePref('system')
})

describe('ThemePicker', () => {
  it('names the current choice on the trigger and switches the scheme from the menu', async () => {
    const user = userEvent.setup()
    render(<ThemePicker />)

    const trigger = screen.getByTestId('theme-picker')
    expect(trigger).toHaveAccessibleName(/System/)

    await user.click(trigger)
    expect(screen.getByTestId('theme-picker-system')).toHaveAttribute('aria-checked', 'true')
    await user.click(screen.getByTestId('theme-picker-dark'))

    expect(window.localStorage.getItem('vp_theme')).toBe('dark')
    expect(screen.getByTestId('theme-picker')).toHaveAccessibleName(/Dunkel|Dark/)
  })
})

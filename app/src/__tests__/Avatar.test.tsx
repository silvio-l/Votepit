/**
 * The round board logo carries its test id on the rendered image, so the plate and
 * ring rules (board-profile-logo) can be asserted per board.
 */

import { render, screen } from '@testing-library/react'
import { Avatar } from '@votepit/ui/ds'
import { describe, expect, it } from 'vitest'

describe('DS Avatar', () => {
  it('puts the test id on the logo image of a large round avatar', () => {
    render(<Avatar size="large" name="Smoke Test" src="/logo.png" testId="board-profile-logo" />)

    const logo = screen.getByTestId('board-profile-logo')
    expect(logo.tagName).toBe('IMG')
    expect(logo).toHaveClass('ds-avatar', 'ds-avatar--large')
  })
})

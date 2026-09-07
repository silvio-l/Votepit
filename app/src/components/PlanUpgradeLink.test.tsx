/**
 * Unit tests for PlanUpgradeLink — the reusable "Upgrade to Pro" link for
 * plan-gated fields. Defensive rendering: nothing on a Community/self-host
 * installation (no `features.billing`), a real link to /admin/billing on a
 * Cloud installation.
 */

import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'
import { setFeatures } from '../lib/features'
import { PlanUpgradeLink } from './PlanUpgradeLink'

afterEach(() => {
  setFeatures(undefined) // reset to Community defaults between tests
})

describe('PlanUpgradeLink', () => {
  it('renders nothing when the installation has no billing extension (Community/self-host)', () => {
    setFeatures({ board_smtp: true, legal_links: null })

    const { container } = render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" />
      </MemoryRouter>,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('renders a link to /admin/billing when the billing extension is present (Cloud)', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" />
      </MemoryRouter>,
    )

    const link = screen.getByRole('link', { name: /Upgrade to Pro/ })
    expect(link).toHaveAttribute('href', '/admin/billing')
  })
})

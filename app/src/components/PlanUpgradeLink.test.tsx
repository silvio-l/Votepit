/**
 * Unit tests for PlanUpgradeLink — the reusable "Upgrade to Pro" link for
 * plan-gated fields. Defensive rendering: nothing on a Community/self-host
 * installation (no `features.billing`). On a Cloud installation only the
 * account owner gets a link to /admin/billing (owner-only page); every other
 * role, and a logged-out or non-member viewer, gets a plain note instead.
 */

import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'
import type { AccountRole } from '../lib/api/members'
import type { User } from '../lib/api/model'
import { setFeatures } from '../lib/features'
import { setViewer } from '../lib/viewer'
import { PlanUpgradeLink } from './PlanUpgradeLink'

const NOTE = 'Den Tarif kann nur der Konto-Owner ändern.'

function viewerWithRole(role: AccountRole): void {
  // accountRoleFor() falls back to the first membership when no slug is set.
  setViewer({ memberships: [{ account_slug: 'demo', role }] } as unknown as User)
}

afterEach(() => {
  setFeatures(undefined) // reset to Community defaults between tests
  setViewer(null)
})

describe('PlanUpgradeLink', () => {
  it('renders nothing when the installation has no billing extension (Community/self-host)', () => {
    setFeatures({ board_smtp: true, legal_links: null })
    viewerWithRole('owner')

    const { container } = render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" />
      </MemoryRouter>,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('renders nothing for a non-owner on a self-host installation', () => {
    setFeatures({ board_smtp: true, legal_links: null })
    viewerWithRole('moderator')

    const { container } = render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" />
      </MemoryRouter>,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('renders a link to /admin/billing for the owner on a Cloud installation', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    viewerWithRole('owner')

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" />
      </MemoryRouter>,
    )

    const link = screen.getByRole('link', { name: /Upgrade to Pro/ })
    expect(link).toHaveAttribute('href', '/admin/billing')
  })

  it.each([
    'admin',
    'moderator',
    'member',
  ] as const)('shows a plain note instead of a dead link for the %s role', (role) => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    viewerWithRole(role)

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" />
      </MemoryRouter>,
    )

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByText(NOTE)).toBeInTheDocument()
  })

  it('shows the note when nobody is signed in', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    setViewer(null)

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" />
      </MemoryRouter>,
    )

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByText(NOTE)).toBeInTheDocument()
  })
})

describe('PlanUpgradeLink — P-38 tier text', () => {
  it('names Lite when the explicit requiredPlan is lite (owner)', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    viewerWithRole('owner')

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" requiredPlan="lite" />
      </MemoryRouter>,
    )

    expect(screen.getByRole('link', { name: /Ab Lite verfügbar/ })).toHaveAttribute(
      'href',
      '/admin/billing',
    )
    expect(screen.queryByText('Upgrade to Pro')).toBeNull()
  })

  it('names Pro when the explicit requiredPlan is pro', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    viewerWithRole('owner')

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" requiredPlan="pro" />
      </MemoryRouter>,
    )

    expect(screen.getByRole('link', { name: /Ab Pro verfügbar/ })).toBeInTheDocument()
  })

  it('falls back to the generic label when no tier is known', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    viewerWithRole('owner')

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" requiredPlan={null} />
      </MemoryRouter>,
    )

    expect(screen.getByRole('link', { name: /Upgrade to Pro/ })).toBeInTheDocument()
  })

  it('reads the required tier from the viewer entitlements for a feature', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    setViewer({
      memberships: [
        {
          account_slug: 'demo',
          role: 'owner',
          entitlements: {
            current_plan: 'lite',
            can_change_plan: true,
            features: {
              visibility_private: { allowed: false, required_plan: 'pro' },
            },
          },
        },
      ],
    } as unknown as User)

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" feature="visibility_private" />
      </MemoryRouter>,
    )

    expect(screen.getByRole('link', { name: /Ab Pro verfügbar/ })).toBeInTheDocument()
  })

  it('keeps the owner-only note for a non-owner, even with a known tier', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    viewerWithRole('moderator')

    render(
      <MemoryRouter>
        <PlanUpgradeLink label="Upgrade to Pro" requiredPlan="lite" />
      </MemoryRouter>,
    )

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByText(NOTE)).toBeInTheDocument()
  })
})

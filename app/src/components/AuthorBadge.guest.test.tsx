/**
 * AuthorBadge — the shared guest author of ideas submitted without an
 * account (ADR 0006) renders as "Guest": no profile link (there is no person
 * behind it) and no role badge.
 */

import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { PublicProfile } from '../lib/api'
import { AuthorBadge } from './AuthorBadge'

function cacheWith(profile: PublicProfile) {
  return new Map([[profile.id, Promise.resolve(profile)]])
}

describe('AuthorBadge — guest author', () => {
  it('renders "Gast" without a profile link', async () => {
    const cache = cacheWith({
      id: 99,
      visible: false,
      guest: true,
      is_admin: false,
      is_operator: false,
      role: null,
    })
    render(
      <MemoryRouter>
        <AuthorBadge
          authorId={99}
          currentUserId={null}
          myAvatarUrl={null}
          currentUserRole={null}
          currentUserIsOperator={false}
          cache={cache}
        />
      </MemoryRouter>,
    )

    expect(await screen.findByText('Gast')).toBeInTheDocument()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})

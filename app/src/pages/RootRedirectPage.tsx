/**
 * RootRedirectPage — bare `/` in cloud mode.
 *
 * Cloud mode's scoped routes are all `/{accountSlug}`-prefixed (see App.tsx's
 * scopedPath()), so a bare `/` matches nothing there — without this page it
 * fell through to the catch-all NotFoundPage, meaning the literal root
 * domain of a cloud-mode instance showed nothing but a 404 for every visitor.
 *
 * Sends the caller wherever they can actually act:
 *   - Anon (no session)                     → /login
 *   - Logged in, has an account membership  → their own /{slug}/admin/boards
 *   - Logged in, no account yet             → /signup/account
 *
 * Self-host is unaffected — its scopedRoutes mount an unprefixed '/' (see
 * scopedPath(subpath, cloud=false)), so this page is only ever reached in
 * cloud mode (see AppRoutes' conditional route registration).
 */

import { LoadingState } from '@votepit/ui'
import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { bootstrap } from '../lib/api'
import { useT } from '../lib/i18n/context'

export default function RootRedirectPage() {
  const navigate = useNavigate()
  const t = useT('common')

  useEffect(() => {
    let cancelled = false

    async function init() {
      const boot = await bootstrap().catch(() => null)
      if (cancelled) return

      if (!boot?.user) {
        navigate('/login', { replace: true })
        return
      }

      const accountSlug = boot.user.memberships[0]?.account_slug
      navigate(accountSlug ? `/${accountSlug}/admin/boards` : '/signup/account', { replace: true })
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate])

  return (
    <div className="vp-container py-10">
      <LoadingState label={t('state.loading')} rows={3} />
    </div>
  )
}

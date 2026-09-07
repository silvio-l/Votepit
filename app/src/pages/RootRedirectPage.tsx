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
 *   - Logged in, no account membership      → /profile
 *
 * The no-membership case used to force /signup/account — but "no account"
 * is the normal, permanent state of a pure voter (someone who only ever
 * votes on other people's boards), not just a transient step every visitor
 * must clear. Forcing the "create your own account+board" wizard on them
 * hijacked their session the moment they landed on the bare root domain.
 * /signup/account stays reachable, just opt-in, via /signup's own CTA (also
 * linked from ProfilePage's empty accounts state).
 *
 * Self-host is unaffected — its scopedRoutes mount an unprefixed '/' (see
 * scopedPath(subpath, cloud=false)), so this page is only ever reached in
 * cloud mode (see AppRoutes' conditional route registration).
 */

import { LoadingState, PageShell } from '@votepit/ui'
import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { bootstrap } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

export default function RootRedirectPage() {
  const navigate = useNavigate()
  const t = useT('common')
  const { language } = useI18n()

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
      navigate(accountSlug ? `/${accountSlug}/admin/boards` : '/profile', { replace: true })
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate])

  return (
    <PageShell width="narrow" legalLinks={legalLinksFor(language)}>
      <LoadingState label={t('state.loading')} rows={3} />
    </PageShell>
  )
}

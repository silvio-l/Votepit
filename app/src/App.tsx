import { appExtensions } from '@votepit/app-extensions'
import { LoadingState } from '@votepit/ui'
import type { ReactElement } from 'react'
import { lazy, Suspense, useEffect, useState } from 'react'
import { Outlet, Route, Routes, useParams } from 'react-router-dom'
import { AppErrorBoundary } from './components/AppErrorBoundary'
import { setAccountSlug } from './lib/accountContext'
import { bootstrap } from './lib/api'
import { setEdition } from './lib/edition'
import { setFeatures } from './lib/features'
import { I18nProvider, useT } from './lib/i18n/context'
import { initSentryFrontend } from './lib/sentry'

// Route-level code splitting: every page is its own chunk, so the board
// visitor never downloads the admin/operator code and vice versa.
const AccountPage = lazy(() => import('./pages/AccountPage'))
const AdminPage = lazy(() => import('./pages/AdminPage'))
const ApiTokensPage = lazy(() => import('./pages/ApiTokensPage'))
const BoardPage = lazy(() => import('./pages/BoardPage'))
const BoardsAdminPage = lazy(() => import('./pages/BoardsAdminPage'))
const EditPage = lazy(() => import('./pages/EditPage'))
const ForgotPasswordPage = lazy(() => import('./pages/ForgotPasswordPage'))
const IdeaDetailPage = lazy(() => import('./pages/IdeaDetailPage'))
const InboxPage = lazy(() => import('./pages/InboxPage'))
const InviteAcceptPage = lazy(() => import('./pages/InviteAcceptPage'))
const LoginPage = lazy(() => import('./pages/LoginPage'))
const MembersPage = lazy(() => import('./pages/MembersPage'))
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'))
const OperatorPage = lazy(() => import('./pages/OperatorPage'))
const ProfilePage = lazy(() => import('./pages/ProfilePage'))
const PublicProfilePage = lazy(() => import('./pages/PublicProfilePage'))
const RoadmapPage = lazy(() => import('./pages/RoadmapPage'))
const ResetPasswordPage = lazy(() => import('./pages/ResetPasswordPage'))
const RootRedirectPage = lazy(() => import('./pages/RootRedirectPage'))
const SignupAccountPage = lazy(() => import('./pages/SignupAccountPage'))
const SignupPage = lazy(() => import('./pages/SignupPage'))
const SubmitPage = lazy(() => import('./pages/SubmitPage'))
const SupportPage = lazy(() => import('./pages/SupportPage'))
const VerifyPage = lazy(() => import('./pages/VerifyPage'))

/**
 * Board-/admin-scoped pages (Config::routingMode, cloud path routing,
 * SPA half). Declared once and mounted twice below — unprefixed in
 * self-host mode, under a leading `/:accountSlug` segment in cloud mode —
 * mirroring the backend's `$accountPrefix` in core/src/Http/AppFactory.php.
 * `subpath` is relative, no leading slash; '' is the account/board root.
 * SPA extensions (`@votepit/app-extensions`) append their own scoped pages.
 */
const scopedRoutes: { subpath: string; element: ReactElement }[] = [
  { subpath: '', element: <BoardPage /> },
  { subpath: ':boardSlug', element: <BoardPage /> },
  { subpath: ':boardSlug/roadmap', element: <RoadmapPage /> },
  { subpath: ':boardSlug/idea/:ideaId', element: <IdeaDetailPage /> },
  { subpath: ':boardSlug/idea/:ideaId/edit', element: <EditPage /> },
  { subpath: ':boardSlug/submit', element: <SubmitPage /> },
  { subpath: 'admin/boards', element: <BoardsAdminPage /> },
  { subpath: 'admin/boards/:boardSlug', element: <AdminPage /> },
  { subpath: 'admin/boards/:boardSlug/tokens', element: <ApiTokensPage /> },
  { subpath: 'admin/members', element: <MembersPage /> },
  { subpath: 'admin/support', element: <SupportPage /> },
  { subpath: 'admin/inbox', element: <InboxPage /> },
  { subpath: 'admin/account', element: <AccountPage /> },
  { subpath: 'profile', element: <ProfilePage /> },
  // Must match the backend path `{account}/members/{userId}/profile`.
  { subpath: 'members/:userId/profile', element: <PublicProfilePage /> },
  ...appExtensions.scopedRoutes,
]

function scopedPath(subpath: string, cloud: boolean): string {
  if (!cloud) return subpath === '' ? '/' : `/${subpath}`
  return subpath === '' ? '/:accountSlug' : `/:accountSlug/${subpath}`
}

/**
 * Layout for every account-/board-scoped route. Reads the `:accountSlug`
 * route param (present only in cloud mode) and stores it in the module-level
 * account context BEFORE rendering the child route — synchronously, in the
 * render body, not a useEffect. Child pages call api.ts functions from their
 * OWN useEffect on mount, which (per React's effect-ordering: children fire
 * before parents) would otherwise race an ancestor effect and read a stale
 * (or absent) account slug on the very first request.
 */
function ScopedLayout() {
  const { accountSlug } = useParams<{ accountSlug: string }>()
  setAccountSlug(accountSlug ?? null)
  return <Outlet />
}

/**
 * Layout for every global/identity-scoped route (login, signup, operator,
 * invite accept, 404 fallback). Explicitly resets the account context to
 * null on every render. Necessary because `currentAccountSlug` is plain
 * module state, not React state tied to this route tree — without this
 * reset, navigating from a cloud-mode account page straight to e.g.
 * `/operator` would leave the previous account's slug live, and any
 * accountPath() call made from the new page would silently mis-scope.
 */
function GlobalLayout() {
  setAccountSlug(null)
  return <Outlet />
}

/** Shown while bootstrap resolves and while a lazy page chunk downloads. */
function LoadingGate() {
  const t = useT('common')
  return (
    <div className="vp-container py-10">
      <LoadingState label={t('state.loading')} rows={5} />
    </div>
  )
}

function AppRoutes() {
  // Fetched once on SPA mount so the router knows, before its first render
  // of scoped routes, whether to expect a leading /:accountSlug segment —
  // see ScopedLayout/scopedPath above and AccountContextMiddleware on the
  // backend, which this mirrors. Per-page bootstrap() calls elsewhere stay
  // as-is (they additionally seed the CSRF token / current user); this call
  // is cheap and idempotent, an extra round trip is not worth avoiding here.
  const [routingMode, setRoutingMode] = useState<'self-host' | 'cloud' | null>(null)

  useEffect(() => {
    let cancelled = false
    bootstrap()
      .then((data) => {
        if (!cancelled) {
          setRoutingMode(data.routing_mode)
          setEdition(data.routing_mode)
          setFeatures(data.features)
          initSentryFrontend(data.sentry_dsn_frontend)
        }
      })
      .catch(() => {
        if (!cancelled) setRoutingMode('self-host')
      })
    return () => {
      cancelled = true
    }
  }, [])

  if (routingMode === null) {
    return <LoadingGate />
  }

  const cloud = routingMode === 'cloud'

  return (
    <Suspense fallback={<LoadingGate />}>
      <Routes>
        <Route element={<GlobalLayout />}>
          {/* Cloud mode only: self-host already mounts an unprefixed '/' via
              scopedRoutes below (subpath ''), so registering it here too would
              create a duplicate/conflicting route. */}
          {cloud && <Route path="/" element={<RootRedirectPage />} />}

          {/* Auth routes — path MUST match what the PHP backend emails:
              $config->appUrl . '/login/verify?token=' . $pair['token'] */}
          <Route path="/login" element={<LoginPage />} />
          <Route path="/login/verify" element={<VerifyPage />} />

          {/* Forgot-password flow — paths MUST match what the PHP backend
              emails/serves: $config->appUrl . '/password/reset/confirm?token='
              . $pair['token'] (PasswordResetRequestAction), the same paths
              double as the POST /password/reset/{request,confirm} API
              actions (AppFactory). */}
          <Route path="/password/reset/request" element={<ForgotPasswordPage />} />
          <Route path="/password/reset/confirm" element={<ResetPasswordPage />} />

          {/* Cloud signup/onboarding — mounted unconditionally
              client-side; the backend routes behind these pages
              (POST /signup/account) only exist in cloud mode (self-host
              simply never links here, and the API 404s if visited anyway). */}
          <Route path="/signup" element={<SignupPage />} />
          <Route path="/signup/account" element={<SignupAccountPage />} />

          {/* Operator panel — unprefixed by any account segment (deliberately
              NOT account-scoped). AuthZ enforced both client-side (bootstrap
              is_operator) and server-side (AuthZMiddleware::operator), one
              tier above accountAdmin/is_admin. */}
          <Route path="/operator" element={<OperatorPage />} />

          {/* Invite accept — path MUST match what the PHP backend emails:
              $config->appUrl . '/invite/accept?token=' . $pair['token'] */}
          <Route path="/invite/accept" element={<InviteAcceptPage />} />

          {/* SPA extensions' global (unprefixed, non account-scoped) routes —
              e.g. a hosted service's platform-wide SaaS admin dashboard,
              sitting above /operator. Must be mounted before the catch-all. */}
          {appExtensions.globalRoutes.map(({ path, element }) => (
            <Route key={path} path={path} element={element} />
          ))}

          <Route path="*" element={<NotFoundPage />} />
        </Route>

        {/* Board-/admin-scoped routes — AuthZ enforced both client-side
            (bootstrap) and server-side (AuthZMiddleware::accountAdmin/
            accountOwner). See scopedRoutes/scopedPath above. */}
        <Route element={<ScopedLayout />}>
          {scopedRoutes.map(({ subpath, element }) => (
            <Route key={subpath || '/'} path={scopedPath(subpath, cloud)} element={element} />
          ))}
        </Route>
      </Routes>
    </Suspense>
  )
}

export default function App() {
  return (
    <I18nProvider>
      <AppErrorBoundary>
        {/* SPA extensions' installation-wide banner slot — above every page. */}
        {appExtensions.slots.appBanner}
        <AppRoutes />
      </AppErrorBoundary>
    </I18nProvider>
  )
}

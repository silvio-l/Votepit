import { appExtensions } from '@votepit/app-extensions'
import { LoadingState } from '@votepit/ui/ds'
import type { ReactElement } from 'react'
import { lazy, Suspense, useEffect, useState } from 'react'
import { Outlet, Route, Routes, useLocation, useParams } from 'react-router-dom'
import { AppErrorBoundary } from './components/AppErrorBoundary'
import { setRoutingMode as cacheRoutingMode, setAccountSlug } from './lib/accountContext'
import { initAnalytics, setAnalyticsConfig, trackPageView } from './lib/analytics'
import { bootstrap } from './lib/api'
import { setEdition } from './lib/edition'
import { setFeatures, setTranslationAvailable } from './lib/features'
import { I18nProvider, useT } from './lib/i18n/context'
import { initSentryFrontend } from './lib/sentry'

// Route-level code splitting: every page is its own chunk, so the board
// visitor never downloads the admin/operator code and vice versa.
const AccountPage = lazy(() => import('./pages/AccountPage'))
const AdminPage = lazy(() => import('./pages/AdminPage'))
const AppealPage = lazy(() => import('./pages/AppealPage'))
const AppealsAdminPage = lazy(() => import('./pages/AppealsAdminPage'))
const ApiTokensPage = lazy(() => import('./pages/ApiTokensPage'))
const BoardPage = lazy(() => import('./pages/BoardPage'))
const BoardPosterPage = lazy(() => import('./pages/BoardPosterPage'))
const BoardsAdminPage = lazy(() => import('./pages/BoardsAdminPage'))
const DiscoverPage = lazy(() => import('./pages/DiscoverPage'))
const EditPage = lazy(() => import('./pages/EditPage'))
const ForgotPasswordPage = lazy(() => import('./pages/ForgotPasswordPage'))
const IdeaDetailPage = lazy(() => import('./pages/IdeaDetailPage'))
const ImpressumPage = lazy(() => import('./pages/ImpressumPage'))
const InboxPage = lazy(() => import('./pages/InboxPage'))
const InviteAcceptPage = lazy(() => import('./pages/InviteAcceptPage'))
const LoginPage = lazy(() => import('./pages/LoginPage'))
const MembersPage = lazy(() => import('./pages/MembersPage'))
const ModerationQueuePage = lazy(() => import('./pages/ModerationQueuePage'))
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'))
const OAuthConsentPage = lazy(() => import('./pages/OAuthConsentPage'))
const NotificationEmailConfirmPage = lazy(() => import('./pages/NotificationEmailConfirmPage'))
const MarketingConsentConfirmPage = lazy(() => import('./pages/MarketingConsentConfirmPage'))
const DigestUnsubscribePage = lazy(() => import('./pages/DigestUnsubscribePage'))
const OperatorNudgeUnsubscribePage = lazy(() => import('./pages/OperatorNudgeUnsubscribePage'))
const ParticipantMailUnsubscribePage = lazy(() => import('./pages/ParticipantMailUnsubscribePage'))
const OperatorPage = lazy(() => import('./pages/OperatorPage'))
const OperatorSupportPage = lazy(() => import('./pages/OperatorSupportPage'))
const OperatorSupportTicketPage = lazy(() => import('./pages/OperatorSupportTicketPage'))
const ProfilePage = lazy(() => import('./pages/ProfilePage'))
const PublicProfilePage = lazy(() => import('./pages/PublicProfilePage'))
const RoadmapPage = lazy(() => import('./pages/RoadmapPage'))
const ResetPasswordPage = lazy(() => import('./pages/ResetPasswordPage'))
const RootRedirectPage = lazy(() => import('./pages/RootRedirectPage'))
const SignupAccountPage = lazy(() => import('./pages/SignupAccountPage'))
const SignupPage = lazy(() => import('./pages/SignupPage'))
const SubmitPage = lazy(() => import('./pages/SubmitPage'))
const SupportPage = lazy(() => import('./pages/SupportPage'))
const SupportTicketPage = lazy(() => import('./pages/SupportTicketPage'))
const TrustedAuthorsPage = lazy(() => import('./pages/TrustedAuthorsPage'))
const VerifyPage = lazy(() => import('./pages/VerifyPage'))
const WorklistPage = lazy(() => import('./pages/WorklistPage'))

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
  { subpath: ':boardSlug/impressum', element: <ImpressumPage /> },
  { subpath: ':boardSlug/idea/:ideaId', element: <IdeaDetailPage /> },
  { subpath: ':boardSlug/idea/:ideaId/edit', element: <EditPage /> },
  { subpath: ':boardSlug/submit', element: <SubmitPage /> },
  { subpath: 'admin/boards', element: <BoardsAdminPage /> },
  { subpath: 'admin/boards/:boardSlug', element: <AdminPage /> },
  // Full-screen design editor (P-27): same page state, no admin shell.
  { subpath: 'admin/boards/:boardSlug/design', element: <AdminPage mode="design" /> },
  { subpath: 'admin/boards/:boardSlug/poster', element: <BoardPosterPage /> },
  { subpath: 'admin/boards/:boardSlug/queue', element: <ModerationQueuePage /> },
  { subpath: 'admin/tokens', element: <ApiTokensPage /> },
  { subpath: 'admin/members', element: <MembersPage /> },
  { subpath: 'admin/support', element: <SupportPage /> },
  { subpath: 'admin/support/:id', element: <SupportTicketPage /> },
  { subpath: 'admin/inbox', element: <InboxPage /> },
  { subpath: 'admin/account', element: <AccountPage /> },
  // board-operator-compliance issue 09: reached via the removal-notification
  // email's link, which the backend builds via {accountPrefix}/appeal/{id}
  // (CommentModerationAction/IdeaModerationAction::appealLinkPath()) —
  // exactly this scoped subpath, unprefixed in self-host / :accountSlug-
  // prefixed in cloud, so no separate GlobalLayout route is needed.
  { subpath: 'appeal/:moderationActionId', element: <AppealPage /> },
  { subpath: 'admin/appeals', element: <AppealsAdminPage /> },
  { subpath: 'admin/trusted-authors', element: <TrustedAuthorsPage /> },
  // P-06 operator worklist (read only in slice 03); API shares the path, see WorklistRoutes.
  { subpath: 'admin/worklist', element: <WorklistPage /> },
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
          cacheRoutingMode(data.routing_mode)
          const productEdition = data.features?.product_edition
          setEdition(
            data.routing_mode,
            productEdition === 'cloud' || productEdition === 'community'
              ? productEdition
              : undefined,
          )
          setFeatures(data.features)
          setTranslationAvailable(data.translation_available)
          initSentryFrontend(data.sentry_dsn_frontend)
          // Test-User feature: a dedicated QA/E2E account (is_test_account)
          // never gets tracked, in production or anywhere else — skip both
          // setAnalyticsConfig() and initAnalytics() entirely for it.
          if (!data.user?.is_test_account) {
            setAnalyticsConfig(data)
            initAnalytics()
          }
        }
      })
      .catch(() => {
        if (!cancelled) {
          setRoutingMode('self-host')
          cacheRoutingMode('self-host')
        }
      })
    return () => {
      cancelled = true
    }
  }, [])

  // Fires on every SPA route change (initial mount included) — see
  // lib/analytics.ts. A no-op until setAnalyticsConfig() above has run, but
  // location changes on first render are rare enough that missing that very
  // first pageview isn't worth sequencing around.
  const location = useLocation()
  useEffect(() => {
    trackPageView(location.pathname)
  }, [location.pathname])

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

          {/* Cloud mode only, same reasoning as '/' above: self-host already
              mounts an unprefixed '/profile' via scopedRoutes. In cloud mode
              ProfilePage is ALSO reachable unprefixed here — it is a pure
              voter's only account-independent page (RootRedirectPage sends a
              member-less user here), and its own data (GET /account/profile)
              is user-scoped, not account-scoped (AppFactory.php), so it needs
              no :accountSlug to render correctly. The prefixed
              /{accountSlug}/profile route (scopedRoutes below) keeps working
              unchanged for links built from inside a board's account
              context. */}
          {cloud && <Route path="/profile" element={<ProfilePage />} />}

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

          {/* Notification-email confirm — path MUST match what the PHP
              backend emails: $config->appUrl .
              '/account/notification-email/confirm?token=' . $pair['token']
              (NotificationPreferencesAction). Same reasoning as /profile
              above: user-scoped, not account-scoped, so no :accountSlug. */}
          <Route
            path="/account/notification-email/confirm"
            element={<NotificationEmailConfirmPage />}
          />

          {/* Product-news consent confirm — path MUST match the backend mail
              (MarketingConsentAction::toggle). User-scoped like the
              notification-e-mail confirm above, so no :accountSlug. */}
          <Route
            path="/account/marketing-consent/confirm"
            element={<MarketingConsentConfirmPage />}
          />

          {/* Weekly digest one-click unsubscribe — path MUST match what the
              PHP backend emails: $config->appUrl . '/digest/unsubscribe?token='
              (WeeklyDigestService). No login needed; user-scoped, not
              account-scoped, so no :accountSlug. */}
          <Route path="/digest/unsubscribe" element={<DigestUnsubscribePage />} />
          {/* Operator nudge mails (onboarding, quiet board, plan limit): one-click, one kind. */}
          <Route path="/operator-nudges/unsubscribe" element={<OperatorNudgeUnsubscribePage />} />
          {/* Participant mails (close-the-loop re-engagement): one-click unsubscribe, the
              link target of the mails' `$config->appUrl . '/mail/unsubscribe?token='`. */}
          <Route path="/mail/unsubscribe" element={<ParticipantMailUnsubscribePage />} />

          {/* Operator panel — unprefixed by any account segment (deliberately
              NOT account-scoped). AuthZ enforced both client-side (bootstrap
              is_operator) and server-side (AuthZMiddleware::operator), one
              tier above accountAdmin/is_admin. */}
          <Route path="/operator" element={<OperatorPage />} />
          <Route path="/operator/support" element={<OperatorSupportPage />} />
          <Route path="/operator/support/:id" element={<OperatorSupportTicketPage />} />

          {/* Invite accept — path MUST match what the PHP backend emails:
              $config->appUrl . '/invite/accept?token=' . $pair['token'] */}
          <Route path="/invite/accept" element={<InviteAcceptPage />} />

          {/* MCP OAuth consent (issue 21) — the authorization_endpoint the
              backend advertises in /.well-known/oauth-authorization-server.
              Unprefixed: the account is chosen ON the page. */}
          <Route path="/oauth/authorize" element={<OAuthConsentPage />} />

          {/* Public board discovery — unprefixed by any account segment
              (deliberately NOT account-scoped, same reasoning as /operator
              above): lists boards across every account, mirroring the
              backend's cross-tenant GET /discover (BoardDiscoveryAction).
              Anon-readable, linked from the marketing landing page. */}
          <Route path="/discover" element={<DiscoverPage />} />

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

import { appExtensions } from '@votepit/app-extensions'
import { Header, Menu, type MenuItem } from '@votepit/ui'
import {
  Bell,
  Eye,
  EyeOff,
  LogOut,
  ServerCog,
  Settings2,
  ShieldCheck,
  UserRound,
} from 'lucide-react'
import type { ComponentProps, ComponentPropsWithoutRef, ReactNode } from 'react'
import { forwardRef, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import { accountRoleFor, listNotifications, type User } from '../lib/api'
import { getEdition } from '../lib/edition'
import { getFeatures } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'
import { Avatar } from './Avatar'
import { LanguageToggle } from './LanguageToggle'

type HeaderProps = ComponentProps<typeof Header>

/**
 * Adapts react-router's `Link` to the `href`-based shape Header/Menu's
 * `linkAs` expects (they own an `href: string` data model, not `to`) —
 * passed to both so in-app nav clicks (page nav, account menu) become
 * client-side navigations instead of full page reloads. Forwards ref: both
 * Header's nav map and Menu's keyboard-navigation item refs need it.
 */
const RouterLink = forwardRef<HTMLAnchorElement, ComponentPropsWithoutRef<'a'> & { href: string }>(
  ({ href, ...props }, ref) => <Link ref={ref} to={href} {...props} />,
)

/**
 * Account-scoped href for pages that only exist under an account (profile,
 * admin). Inside an account (or on self-host, which is always exactly one
 * account) that is `accountPath()`. On a cloud-mode page without an account
 * segment (operator panel, signup) there is no current account, so the link
 * targets the visitor's first membership — or nothing, if there is none.
 *
 * `/profile` is the one exception: it also exists unprefixed in cloud mode
 * (App.tsx registers it both scoped and global — see RootRedirectPage),
 * since it is a pure voter's only account-independent page. A visitor with
 * zero memberships still gets a working Profil link there instead of losing
 * it entirely.
 */
export function accountAreaHref(user: User | null, path: string): string | null {
  if (getAccountSlug() !== null || getEdition() !== 'cloud') return accountPath(path)
  const slug = user?.memberships?.[0]?.account_slug
  if (slug !== undefined) return `/${slug}${path}`
  return path === '/profile' ? path : null
}

/**
 * The one grouped control for everything that is "me", not "this board":
 * profile, the account-admin area (owners/admins/instance admins), the
 * operator panel (platform operators) and logout. Server-side AuthZ
 * (AuthZMiddleware::accountAdmin/operator) is the actual gate; this only
 * decides which entries are worth showing (a link to a 403 helps no one).
 */
function AccountMenu({
  user,
  onLogout,
  voterPreview = false,
  boardSlug,
  onVoterPreviewChange,
}: {
  user: User | null
  onLogout?: () => void
  /** See LocalizedHeaderProps.voterPreview — suppresses every admin/operator entry. */
  voterPreview?: boolean
  /** See LocalizedHeaderProps.boardSlug — gates the "view as voter" entry below. */
  boardSlug?: string
  /** See LocalizedHeaderProps.onVoterPreviewChange. */
  onVoterPreviewChange?: (checked: boolean) => void
}) {
  const t = useT('common')
  const role = accountRoleFor(user, getAccountSlug())
  // Unlike `canModerate` below, this ignores the voterPreview suppression —
  // the toggle to LEAVE preview mode must stay visible while it's active,
  // even though every other admin/operator entry in this menu is hidden for
  // the duration (the whole point of previewing is seeing the voter's menu).
  const canActuallyModerate =
    user !== null && (user.is_admin || role === 'owner' || role === 'admin')
  const canModerate = !voterPreview && canActuallyModerate
  const profileHref = accountAreaHref(user, '/profile')
  const adminHref = canModerate ? accountAreaHref(user, '/admin/boards') : null
  const here = typeof window !== 'undefined' ? window.location.pathname : ''

  const items: MenuItem[] = []
  if (profileHref !== null) {
    items.push({
      kind: 'link',
      label: t('header.profile'),
      href: profileHref,
      current: here === profileHref,
      icon: <UserRound size={15} />,
    })
  }
  if (adminHref !== null) {
    items.push({
      kind: 'link',
      label: t('header.adminArea'),
      href: adminHref,
      current: here.startsWith(adminHref.replace(/\/boards$/, '')),
      icon: <Settings2 size={15} />,
    })
  }
  if (!voterPreview && !user?.is_operator && user?.is_support) {
    items.push({
      kind: 'link',
      label: t('header.operatorSupport'),
      href: '/operator/support',
      current: here.startsWith('/operator/support'),
      icon: <ServerCog size={15} />,
    })
  }
  if (!voterPreview && user?.is_operator) {
    items.push({
      kind: 'link',
      label: t('header.operator'),
      href: '/operator',
      current: here === '/operator',
      icon: <ServerCog size={15} />,
    })
    if (getEdition() === 'cloud') {
      items.push({
        kind: 'link',
        label: t('header.saasAdmin'),
        href: '/admin/overview',
        current: here.startsWith('/admin/'),
        icon: <ShieldCheck size={15} />,
      })
    }
  }
  if (canActuallyModerate && boardSlug !== undefined && onVoterPreviewChange) {
    if (items.length > 0) items.push({ kind: 'separator' })
    items.push({
      kind: 'action',
      label: voterPreview ? t('voterPreview.toggleOffLabel') : t('voterPreview.toggleLabel'),
      onSelect: () => onVoterPreviewChange(!voterPreview),
      icon: voterPreview ? <EyeOff size={15} /> : <Eye size={15} />,
    })
    if (voterPreview) {
      items.push({
        kind: 'link',
        label: t('voterPreview.backToAdmin'),
        href: accountPath(`/admin/boards/${boardSlug}`),
        icon: <Settings2 size={15} />,
      })
    }
  }
  if (onLogout) {
    if (items.length > 0) items.push({ kind: 'separator' })
    items.push({
      kind: 'action',
      label: t('header.logout'),
      onSelect: onLogout,
      icon: <LogOut size={15} />,
    })
  }

  // Only reflects the preview in the trigger for someone who could actually
  // toggle it back — a real voter opening a `?view=voter` link is not "in
  // preview", that's just their normal view.
  const previewIndicator = voterPreview && canActuallyModerate
  const accountLabel = previewIndicator ? t('voterPreview.toggleOffLabel') : t('header.account')

  return (
    <Menu
      label={
        <>
          <Avatar avatarUrl={user?.avatar_url ?? null} size={22} alt="" />
          {/* Kept visible even though the menu items below collapse to the
              plain voter set while previewing — without this, an admin who
              opened the preview from a different page has no on-screen
              reminder that they're seeing a simulated view. */}
          <span className="hidden sm:inline">{accountLabel}</span>
        </>
      }
      ariaLabel={accountLabel}
      items={items}
      align="end"
      triggerVariant="ghost"
      linkAs={RouterLink}
    />
  )
}

/**
 * Section nav for the account-admin pages (BoardsAdminPage, AdminPage,
 * ApiTokensPage, MembersPage, BillingPage): the three things an account
 * administers. Personal entries (profile, logout) live in the account menu,
 * never here. accountPath() resolves to self-host's unprefixed paths too,
 * so this works in both routing modes.
 *
 * The inbox is deliberately not one of these text links — it is a
 * notification stream (support replies, announcements), not an admin
 * section, so it gets its own bell icon in the trailing controls instead
 * (see NotificationBell below) — same split as most SaaS dashboards keep
 * between page nav and a notification bell.
 */
export function useAccountAdminNavLinks(): Array<{ label: string; href: string }> {
  const t = useT('common')
  const { language } = useI18n()
  return [
    { label: t('header.boardsAdmin'), href: accountPath('/admin/boards') },
    { label: t('header.members'), href: accountPath('/admin/members') },
    { label: t('header.support'), href: accountPath('/admin/support') },
    // SPA extensions (e.g. a hosted service's billing page) slot in here.
    ...appExtensions.adminNavLinks.map((link) => ({
      label: link.label[language],
      href: accountPath(`/${link.subpath}`),
    })),
    { label: t('header.accountSettings'), href: accountPath('/admin/account') },
  ]
}

/** Poll interval for the unread-notification dot — near-real-time without hammering the API. */
const NOTIFICATION_POLL_INTERVAL_MS = 20_000

/**
 * Bell icon for the in-app inbox, with a dot while any notification is
 * unread. Fetches the caller's notifications on mount, then polls on the
 * same interval/visibility-aware pattern as the board vote poll (see
 * useBoardVotes.ts) so a new support ticket or reply lights the dot up
 * without requiring a full page reload — paused while the tab is hidden.
 */
function NotificationBell({ user }: { user: User | null }) {
  const t = useT('common')
  const [hasUnread, setHasUnread] = useState(false)
  // A pure operator/support persona (no account membership — the common
  // case for platform staff, see AuthZMiddleware::support()) gets no
  // account-scoped inbox to link to; without this fallback the bell
  // rendered nothing at all for them, hiding even operator-scoped
  // notifications (support tickets) they otherwise do receive.
  const href =
    accountAreaHref(user, '/admin/inbox') ??
    (user?.is_operator || user?.is_support ? '/operator/support' : null)
  const here = typeof window !== 'undefined' ? window.location.pathname : ''

  useEffect(() => {
    if (href === null) return

    let cancelled = false
    const check = () => {
      listNotifications()
        .then(({ notifications }) => {
          if (!cancelled) setHasUnread(notifications.some((n) => !n.is_read))
        })
        .catch(() => {
          // Silent — a failed unread check is not worth surfacing to the user.
        })
    }

    check()
    const intervalId = window.setInterval(() => {
      if (!document.hidden) check()
    }, NOTIFICATION_POLL_INTERVAL_MS)

    return () => {
      cancelled = true
      window.clearInterval(intervalId)
    }
  }, [href])

  if (href === null) return null

  return (
    <a
      href={href}
      aria-label={hasUnread ? t('header.inboxUnread') : t('header.inbox')}
      aria-current={here === href ? 'page' : undefined}
      className="relative inline-flex items-center justify-center size-9 rounded-vp-md text-vp-text-secondary hover:text-vp-ink hover:bg-vp-ink-soft aria-[current=page]:bg-vp-ink-soft aria-[current=page]:text-vp-ink transition-colors duration-150 vp-press"
    >
      <Bell size={18} aria-hidden="true" />
      {hasUnread && (
        <span
          aria-hidden="true"
          className="absolute top-2 right-2 size-2 rounded-vp-full bg-vp-vote-down ring-2 ring-vp-bg animate-vp-stamp"
        />
      )}
    </a>
  )
}

/**
 * Scope line for the app bar: which account (cloud mode: the URL's account
 * slug) and which board/section the visitor is acting in. Tenant isolation
 * is a structural guarantee — this keeps it *visible* on every page.
 */
export function ScopeLabel({ section }: { section?: string }) {
  const accountSlug = getAccountSlug()
  const parts: ReactNode[] = []
  if (accountSlug) {
    parts.push(
      <span key="account" className="font-mono-num text-vp-xs text-vp-text-muted">
        {accountSlug}
      </span>,
    )
  }
  if (section) {
    if (parts.length > 0)
      parts.push(
        <span key="sep" aria-hidden="true" className="text-vp-text-muted">
          {' / '}
        </span>,
      )
    parts.push(
      <span key="section" className="font-medium text-vp-ink">
        {section}
      </span>,
    )
  }
  if (parts.length === 0) return null
  return <>{parts}</>
}

/**
 * Drop-in replacement for @votepit/ui's Header that wires the app-level i18n
 * dictionary into its label props and adds the language switcher and the
 * account menu. @votepit/ui itself stays free of any app-specific i18n or
 * session dependency (it's also consumed standalone), so both happen at this
 * app-layer wrapper instead.
 *
 * Two zones, kept apart on purpose: the nav names where the visitor is
 * (Board / Roadmap / Settings of the current board, or Boards / Members /
 * Billing inside the admin area); the trailing controls are about the
 * visitor (language, and one account menu or one login button).
 */
interface LocalizedHeaderProps extends Omit<HeaderProps, 'navLinks' | 'account' | 'edition'> {
  /** The bootstrap user — decides which account-menu entries are shown. */
  user?: User | null
  /** Whether a session exists (may be known before `user` resolves). */
  isAuthenticated?: boolean
  onLogoutClick?: () => void
  /**
   * Slug of the board the page is scoped to. With `basePath` this builds
   * the board nav (Board / Roadmap) and, for owners/admins, adds the
   * board's Settings link — one click from any board page into its admin.
   */
  boardSlug?: string
  /** Admin pages pass their section nav instead of the board nav. */
  navLinks?: HeaderProps['navLinks']
  /**
   * Set by AdminShell, whose sidebar footer already carries the session menu
   * (profile + logout, see AdminShell.tsx) — hides the top-bar account menu
   * entirely instead of showing the same destinations twice.
   */
  compactAccountMenu?: boolean
  /**
   * True while the caller hasn't resolved bootstrap() yet — `isAuthenticated`
   * defaults to `false` on first render, which would otherwise flash the
   * login button for a second before it's replaced once the real session is
   * known. While pending, the account slot renders nothing (neither login
   * button nor account menu) instead of guessing.
   */
  authPending?: boolean
  /**
   * Set by BoardPage's "view as voter" toggle: suppresses every
   * admin/operator-only affordance (board-settings nav link, the account
   * menu's admin/operator entries) so the header renders exactly as it
   * would for a real voter, while the page underneath stays the same
   * (BoardPage already shows anon and admin visitors the same idea list —
   * these header extras are the only difference between the two views).
   */
  voterPreview?: boolean
  /**
   * Wires the account menu's "view as voter" entry (see AccountMenu) to the
   * page's useVoterPreview() setter. Without it the entry is not rendered —
   * a page that passes `voterPreview` without this is treated as read-only
   * display state, not an offer to toggle it from here.
   */
  onVoterPreviewChange?: (checked: boolean) => void
}

export function LocalizedHeader({
  user = null,
  isAuthenticated = false,
  onLogoutClick,
  boardSlug,
  navLinks,
  basePath = '',
  compactAccountMenu = false,
  authPending = false,
  voterPreview = false,
  onVoterPreviewChange,
  ...props
}: LocalizedHeaderProps) {
  const t = useT('common')

  const role = accountRoleFor(user, getAccountSlug())
  const canModerate =
    !voterPreview && user !== null && (user.is_admin || role === 'owner' || role === 'admin')

  // The public demo (PublicDemoExtension) declares this feature — every page
  // of the demo gets a "back to votepit.com" link, since visitors reach the
  // demo from the marketing site and otherwise have no way back out of it.
  const isDemo = getFeatures().demo !== undefined
  const homeHref = props.homeHref ?? (isDemo ? 'https://votepit.com' : undefined)
  const homeLabel = props.homeLabel ?? (isDemo ? t('header.homeLabel') : undefined)

  const links =
    navLinks ??
    (boardSlug !== undefined
      ? [
          { label: t('header.board'), href: basePath || '/' },
          { label: t('header.roadmap'), href: `${basePath}/roadmap` },
          ...(canModerate
            ? [
                {
                  label: t('header.boardSettings'),
                  href: accountPath(`/admin/boards/${boardSlug}`),
                },
              ]
            : []),
        ]
      : undefined)

  return (
    <Header
      logoAriaLabel={t('header.logoAriaLabel')}
      navAriaLabel={t('header.navAriaLabel')}
      boardLabel={t('header.board')}
      roadmapLabel={t('header.roadmap')}
      loginLabel={t('header.login')}
      edition={getEdition()}
      demo={isDemo}
      homeHref={homeHref}
      homeLabel={homeLabel}
      basePath={basePath}
      navLinks={links}
      navExtra={
        <>
          {isAuthenticated && <NotificationBell user={user} />}
          <LanguageToggle />
        </>
      }
      account={
        // `undefined` would make Header fall back to its login button — an
        // empty fragment keeps the slot "filled" without rendering
        // anything, both while the real session is still unknown
        // (authPending) and for the compact case where the sidebar footer
        // is the one session menu (see AdminShell.tsx).
        authPending ? null : isAuthenticated ? (
          compactAccountMenu ? null : (
            <AccountMenu
              user={user}
              onLogout={onLogoutClick}
              voterPreview={voterPreview}
              boardSlug={boardSlug}
              onVoterPreviewChange={onVoterPreviewChange}
            />
          )
        ) : undefined
      }
      currentHref={typeof window !== 'undefined' ? window.location.pathname : undefined}
      linkAs={RouterLink}
      {...props}
    />
  )
}

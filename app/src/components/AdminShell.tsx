/**
 * AdminShell — the operate-mode frame for every account-admin page
 * (BoardsAdminPage, AdminPage, ApiTokensPage, MembersPage, SupportPage,
 * InboxPage, AccountPage) and for the platform-operator area (the operator
 * panel plus whatever an extension mounts next to it, e.g. a hosted
 * service's tenant directory).
 *
 * Wraps @votepit/ui's AppShell: the glass top bar carries scope, language,
 * notifications and the session menu; the sidebar carries every section the
 * signed-in user can reach, grouped, with glyphs, so the top bar never has
 * to. The same three groups render on every AdminShell page, account-admin
 * routes and platform-operator routes alike — nothing in the sidebar
 * disappears when navigating between them, which used to make the shell
 * feel like it had switched applications:
 *   - Manage   — boards, members, support, extension pages, account settings.
 *   - Personal — inbox, profile.
 *   - Platform — operators only: an extension's platform pages
 *                (`appExtensions.platformNavLinks`, e.g. overview / tenants /
 *                promotions) followed by core's operator panel.
 * `area` only picks the top-bar scope label and which group is "home" for a
 * page with no account in the URL (falls back to the operator's own first
 * membership so Manage/Personal still resolve — accountAreaHref in
 * LocalizedHeader.tsx). There is no separate account menu in the top bar
 * here: the sidebar footer is the one session control (avatar, profile,
 * logout), so there is exactly one place to sign out or reach the profile,
 * not two.
 */

import { appExtensions } from '@votepit/app-extensions'
import {
  AppShell,
  Badge,
  Menu,
  type MenuItem,
  SidebarNav,
  type SidebarNavGroup,
  type SidebarNavItem,
} from '@votepit/ui'
import {
  Inbox,
  KeyRound,
  LayoutGrid,
  LifeBuoy,
  LogOut,
  Puzzle,
  ServerCog,
  Settings2,
  UserRound,
  Users,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import { accountRoleFor, type User } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'
import { Avatar } from './Avatar'
import { accountAreaHref, LocalizedHeader, ScopeLabel } from './LocalizedHeader'
import { OperatorBadge, RoleBadge } from './RoleBadge'
import { StarFeedbackPrompt } from './StarFeedbackPrompt'

interface AdminShellProps {
  user: User | null
  isAuthenticated: boolean
  /**
   * True while the page's own bootstrap() call hasn't resolved yet —
   * `isAuthenticated` defaults to `false` before that, which would
   * otherwise flash a login button in the header for a moment on every
   * load before the real session state replaces it. Pass
   * `pageState.phase === 'loading'` (or equivalent) here.
   */
  authPending?: boolean
  onLogout: () => void
  onLogin: () => void
  /** Scope line in the top bar; defaults to the admin scope label. */
  scope?: string
  /** Where the logo links to; defaults to the account root. */
  logoHref?: string
  /** "operator" swaps the account sections for the platform ones. */
  area?: 'account' | 'operator'
  /**
   * Content width, same presets as PageShell: "default" is fluid (lists,
   * tables, dashboards), "wide" a generous bounded column (long settings
   * forms), "narrow" a reading/form column.
   */
  width?: 'default' | 'narrow' | 'wide'
  children: ReactNode
}

const ICON_SIZE = 16

function currentHrefOf(items: SidebarNavItem[], pathname: string): string | null {
  let best: string | null = null
  for (const item of items) {
    const matches = pathname === item.href || pathname.startsWith(`${item.href}/`)
    if (matches && (best === null || item.href.length > best.length)) best = item.href
  }
  return best
}

export function AdminShell({
  user,
  isAuthenticated,
  authPending = false,
  onLogout,
  onLogin,
  scope,
  logoHref,
  area = 'account',
  width = 'default',
  children,
}: AdminShellProps) {
  const t = useT('common')
  const { language } = useI18n()
  const pathname = typeof window !== 'undefined' ? window.location.pathname : ''
  const accountSlug = getAccountSlug()
  const role = accountRoleFor(user, accountSlug)
  const canModerate = user !== null && (user.is_admin || role === 'owner' || role === 'admin')

  // The platform-operator sections: an extension's platform pages first (its
  // overview is the natural landing), core's operator panel last. Shown as
  // the whole sidebar in the operator area and as a "Platform" group at the
  // foot of the account area for operators.
  const platformSections: SidebarNavItem[] = [
    ...appExtensions.platformNavLinks.map((link) => ({
      label: link.label[language],
      href: link.path,
      icon: link.icon ?? <Puzzle size={ICON_SIZE} />,
    })),
    { label: t('header.operator'), href: '/operator', icon: <ServerCog size={ICON_SIZE} /> },
    {
      label: t('header.operatorSupport'),
      href: '/operator/support',
      icon: <LifeBuoy size={ICON_SIZE} />,
    },
  ]

  // Every AdminShell page shows the same three groups — account-admin
  // (Manage), personal (Personal) and, for operators, platform (Platform).
  // `area` only picks which one is "home" for the top-bar scope label; it
  // never hides a group, so navigating between e.g. the platform overview
  // and the account's own board list never makes the rest of the sidebar
  // vanish (a jarring, inconsistent switch users otherwise notice).
  //
  // On a page with no account in the URL (the platform-operator routes),
  // `accountPath()` can't build a correct account-admin link — there is no
  // "current" account. Fall back to the operator's own first membership,
  // same as the header's account menu already does, so Manage/Personal stay
  // populated instead of silently disappearing.
  const manageHref = (path: string) => accountAreaHref(user, path)

  const manageItemsRaw: Array<Omit<SidebarNavItem, 'href'> & { href: string | null }> = !canModerate
    ? []
    : [
        {
          label: t('header.boardsAdmin'),
          href: manageHref('/admin/boards'),
          icon: <LayoutGrid size={ICON_SIZE} />,
        },
        {
          label: t('header.apiTokens'),
          href: manageHref('/admin/tokens'),
          icon: <KeyRound size={ICON_SIZE} />,
        },
        {
          label: t('header.members'),
          href: manageHref('/admin/members'),
          icon: <Users size={ICON_SIZE} />,
        },
        {
          label: t('header.support'),
          href: manageHref('/admin/support'),
          icon: <LifeBuoy size={ICON_SIZE} />,
        },
        ...appExtensions.adminNavLinks.map((link) => ({
          label: link.label[language],
          href: manageHref(`/${link.subpath}`),
          icon: <Puzzle size={ICON_SIZE} />,
        })),
        {
          label: t('header.accountSettings'),
          href: manageHref('/admin/account'),
          icon: <Settings2 size={ICON_SIZE} />,
        },
      ]
  const manageItems = manageItemsRaw.filter((item): item is SidebarNavItem => item.href !== null)

  const personalItemsRaw: Array<Omit<SidebarNavItem, 'href'> & { href: string | null }> = [
    {
      label: t('header.inbox'),
      href: manageHref('/admin/inbox'),
      icon: <Inbox size={ICON_SIZE} />,
    },
    {
      label: t('header.profile'),
      href: manageHref('/profile'),
      icon: <UserRound size={ICON_SIZE} />,
    },
  ]
  const personalItems = personalItemsRaw.filter(
    (item): item is SidebarNavItem => item.href !== null,
  )

  // Support-only users (is_support without is_operator) get just the
  // support-ticket link, not the full operator dashboard — see
  // AuthZMiddleware::support() class doc for the tier split.
  const supportOnlySections: SidebarNavItem[] = [
    {
      label: t('header.operatorSupport'),
      href: '/operator/support',
      icon: <LifeBuoy size={ICON_SIZE} />,
    },
  ]
  const platformItems: SidebarNavItem[] = user?.is_operator
    ? platformSections
    : user?.is_support
      ? supportOnlySections
      : []

  const all = [...manageItems, ...personalItems, ...platformItems]
  const current = currentHrefOf(all, pathname)
  const mark = (items: SidebarNavItem[]) =>
    items.map((item) => ({ ...item, current: item.href === current }))

  const groups: SidebarNavGroup[] = []
  if (manageItems.length > 0)
    groups.push({ label: t('shell.groupManage'), items: mark(manageItems) })
  if (personalItems.length > 0)
    groups.push({ label: t('shell.groupPersonal'), items: mark(personalItems) })
  if (platformItems.length > 0)
    groups.push({ label: t('shell.groupPlatform'), items: mark(platformItems) })

  const header = (
    <LocalizedHeader
      logoHref={logoHref ?? accountPath('/')}
      navLinks={[]}
      isAuthenticated={isAuthenticated}
      authPending={authPending}
      user={user}
      onLogoutClick={onLogout}
      onLoginClick={onLogin}
      compactAccountMenu
      scope={
        <ScopeLabel
          section={
            scope ?? (area === 'operator' ? t('header.scopeOperator') : t('header.scopeAdmin'))
          }
        />
      }
    />
  )

  const profileHref = accountAreaHref(user, '/profile')
  const sessionMenuItems: MenuItem[] = []
  if (profileHref !== null) {
    sessionMenuItems.push({
      kind: 'link',
      label: t('header.profile'),
      href: profileHref,
      current: pathname === profileHref,
      icon: <UserRound size={15} />,
    })
  }
  sessionMenuItems.push({ kind: 'separator' })
  sessionMenuItems.push({
    kind: 'action',
    label: t('header.logout'),
    onSelect: onLogout,
    icon: <LogOut size={15} />,
  })

  const sidebarFooter = isAuthenticated ? (
    <Menu
      label={
        <>
          <Avatar avatarUrl={user?.avatar_url ?? null} size={22} alt="" />
          <span className="min-w-0 flex-1 truncate text-left font-mono-num">
            {user?.username ?? accountSlug ?? user?.public_id ?? ''}
          </span>
          {user?.is_operator ? (
            <OperatorBadge />
          ) : role !== null ? (
            <RoleBadge role={role} />
          ) : user?.is_support ? (
            <Badge tone="accent" size="sm">
              {t('header.scopeSupport')}
            </Badge>
          ) : null}
        </>
      }
      ariaLabel={t('shell.sessionMenuAriaLabel')}
      items={sessionMenuItems}
      align="start"
      placement="up"
      triggerVariant="ghost"
      className="w-full [&>button]:w-full"
    />
  ) : undefined

  return (
    <AppShell
      header={header}
      sidebar={<SidebarNav groups={groups} ariaLabel={t('shell.sidebarAriaLabel')} />}
      sidebarFooter={sidebarFooter}
      sidebarAriaLabel={t('shell.sidebarAriaLabel')}
      openSidebarLabel={t('shell.openSidebar')}
      closeSidebarLabel={t('shell.closeSidebar')}
      legalLinks={legalLinksFor(language)}
      width={width}
    >
      {/* Account-admin only (owner/admin/moderator) — never shown to
          anonymous voters or in the platform-operator area. */}
      {canModerate && area === 'account' && <StarFeedbackPrompt />}
      {children}
    </AppShell>
  )
}

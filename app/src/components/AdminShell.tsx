/**
 * AdminShell — the operate-mode frame for every account-admin page
 * (BoardsAdminPage, AdminPage, ApiTokensPage, MembersPage, SupportPage,
 * InboxPage, AccountPage) and the operator panel.
 *
 * Wraps @votepit/ui's AppShell: the glass top bar carries scope, language,
 * notifications and the session menu; the sidebar carries the account's
 * sections (grouped, with glyphs) so the top bar never has to. Section
 * links come from the same `useAccountAdminNavLinks()` the old text nav
 * used — extensions slot in unchanged — and the current section is the
 * longest matching href prefix of the page's pathname.
 */

import { appExtensions } from '@votepit/app-extensions'
import { AppShell, Badge, SidebarNav, type SidebarNavGroup, type SidebarNavItem } from '@votepit/ui'
import {
  Inbox,
  LayoutGrid,
  LifeBuoy,
  Puzzle,
  ServerCog,
  Settings2,
  ShieldCheck,
  UserRound,
  Users,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import { accountRoleFor, type User } from '../lib/api'
import { getEdition } from '../lib/edition'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'
import { Avatar } from './Avatar'
import { LocalizedHeader, ScopeLabel } from './LocalizedHeader'
import { RoleBadge } from './RoleBadge'

interface AdminShellProps {
  user: User | null
  isAuthenticated: boolean
  onLogout: () => void
  onLogin: () => void
  /** Scope line in the top bar; defaults to the admin scope label. */
  scope?: string
  /** Where the logo links to; defaults to the account root. */
  logoHref?: string
  /** "operator" swaps the account sections for the platform ones. */
  area?: 'account' | 'operator'
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
  const edition = getEdition()

  const manageItems: SidebarNavItem[] =
    area === 'operator'
      ? [
          { label: t('header.operator'), href: '/operator', icon: <ServerCog size={ICON_SIZE} /> },
          ...(edition === 'cloud'
            ? [
                {
                  label: t('header.saasAdmin'),
                  href: '/admin/overview',
                  icon: <ShieldCheck size={ICON_SIZE} />,
                },
              ]
            : []),
        ]
      : [
          {
            label: t('header.boardsAdmin'),
            href: accountPath('/admin/boards'),
            icon: <LayoutGrid size={ICON_SIZE} />,
          },
          {
            label: t('header.members'),
            href: accountPath('/admin/members'),
            icon: <Users size={ICON_SIZE} />,
          },
          {
            label: t('header.support'),
            href: accountPath('/admin/support'),
            icon: <LifeBuoy size={ICON_SIZE} />,
          },
          ...appExtensions.adminNavLinks.map((link) => ({
            label: link.label[language],
            href: accountPath(`/${link.subpath}`),
            icon: <Puzzle size={ICON_SIZE} />,
          })),
          {
            label: t('header.accountSettings'),
            href: accountPath('/admin/account'),
            icon: <Settings2 size={ICON_SIZE} />,
          },
        ]

  const personalItems: SidebarNavItem[] =
    area === 'operator'
      ? []
      : [
          {
            label: t('header.inbox'),
            href: accountPath('/admin/inbox'),
            icon: <Inbox size={ICON_SIZE} />,
          },
          {
            label: t('header.profile'),
            href: accountPath('/profile'),
            icon: <UserRound size={ICON_SIZE} />,
          },
        ]

  const platformItems: SidebarNavItem[] =
    area === 'account' && user?.is_operator
      ? [
          { label: t('header.operator'), href: '/operator', icon: <ServerCog size={ICON_SIZE} /> },
          ...(edition === 'cloud'
            ? [
                {
                  label: t('header.saasAdmin'),
                  href: '/admin/overview',
                  icon: <ShieldCheck size={ICON_SIZE} />,
                },
              ]
            : []),
        ]
      : []

  const all = [...manageItems, ...personalItems, ...platformItems]
  const current = currentHrefOf(all, pathname)
  const mark = (items: SidebarNavItem[]) =>
    items.map((item) => ({ ...item, current: item.href === current }))

  const groups: SidebarNavGroup[] = [
    {
      label: area === 'operator' ? t('shell.groupPlatform') : t('shell.groupManage'),
      items: mark(manageItems),
    },
  ]
  if (personalItems.length > 0)
    groups.push({ label: t('shell.groupPersonal'), items: mark(personalItems) })
  if (platformItems.length > 0)
    groups.push({ label: t('shell.groupPlatform'), items: mark(platformItems) })

  const header = (
    <LocalizedHeader
      logoHref={logoHref ?? accountPath('/')}
      navLinks={[]}
      isAuthenticated={isAuthenticated}
      user={user}
      onLogoutClick={onLogout}
      onLoginClick={onLogin}
      scope={
        <ScopeLabel
          section={
            scope ?? (area === 'operator' ? t('header.scopeOperator') : t('header.scopeAdmin'))
          }
        />
      }
    />
  )

  const sidebarFooter = isAuthenticated ? (
    <div className="flex items-center gap-2.5 px-1.5 py-1">
      <Avatar avatarUrl={user?.avatar_url ?? null} size={28} alt="" />
      <div className="min-w-0 flex-1">
        <div className="text-vp-xs font-medium text-vp-ink leading-4 truncate">
          {t('shell.signedIn')}
        </div>
        <div className="text-vp-2xs text-vp-text-muted leading-4 font-mono-num truncate">
          {accountSlug ?? edition}
        </div>
      </div>
      {role !== null ? (
        <RoleBadge role={role} />
      ) : user?.is_operator ? (
        <Badge tone="accent" size="sm">
          {t('header.scopeOperator')}
        </Badge>
      ) : null}
    </div>
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
      {children}
    </AppShell>
  )
}

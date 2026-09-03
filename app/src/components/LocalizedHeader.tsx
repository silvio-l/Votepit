import { appExtensions } from '@votepit/app-extensions'
import { Header, Menu, type MenuItem, Select } from '@votepit/ui'
import { Bell, LogOut, ServerCog, Settings2, ShieldCheck, UserRound } from 'lucide-react'
import type { ComponentProps, ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import { accountRoleFor, listNotifications, type User } from '../lib/api'
import { getEdition } from '../lib/edition'
import { useI18n, useT } from '../lib/i18n/context'
import { Avatar } from './Avatar'

type HeaderProps = ComponentProps<typeof Header>

function LanguageSwitcher() {
  const t = useT('common')
  const { language, setLanguage } = useI18n()

  return (
    <Select
      label={t('language.label')}
      hideLabel
      size="sm"
      value={language}
      onChange={(v) => setLanguage(v as 'de' | 'en')}
      className="w-[7.5rem]"
    >
      <option value="de">{t('language.de')}</option>
      <option value="en">{t('language.en')}</option>
    </Select>
  )
}

/**
 * Account-scoped href for pages that only exist under an account (profile,
 * admin). Inside an account (or on self-host, which is always exactly one
 * account) that is `accountPath()`. On a cloud-mode page without an account
 * segment (operator panel, signup) there is no current account, so the link
 * targets the visitor's first membership — or nothing, if there is none.
 */
function accountAreaHref(user: User | null, path: string): string | null {
  if (getAccountSlug() !== null || getEdition() !== 'cloud') return accountPath(path)
  const slug = user?.memberships?.[0]?.account_slug
  return slug === undefined ? null : `/${slug}${path}`
}

/**
 * The one grouped control for everything that is "me", not "this board":
 * profile, the account-admin area (owners/moderators/instance admins), the
 * operator panel (platform operators) and logout. Server-side AuthZ
 * (AuthZMiddleware::accountAdmin/operator) is the actual gate; this only
 * decides which entries are worth showing (a link to a 403 helps no one).
 */
function AccountMenu({ user, onLogout }: { user: User | null; onLogout?: () => void }) {
  const t = useT('common')
  const role = accountRoleFor(user, getAccountSlug())
  const canModerate = user !== null && (user.is_admin || role === 'owner' || role === 'moderator')
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
  if (user?.is_operator) {
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
  if (onLogout) {
    if (items.length > 0) items.push({ kind: 'separator' })
    items.push({
      kind: 'action',
      label: t('header.logout'),
      onSelect: onLogout,
      icon: <LogOut size={15} />,
    })
  }

  return (
    <Menu
      label={
        <>
          <Avatar avatarUrl={user?.avatar_url ?? null} size={22} alt="" />
          <span className="hidden sm:inline">{t('header.account')}</span>
        </>
      }
      ariaLabel={t('header.account')}
      items={items}
      align="end"
      triggerVariant="ghost"
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

/**
 * Bell icon for the in-app inbox, with a dot while any notification is
 * unread. Fetches the caller's notifications once per page mount (the app
 * has no persistent shell — every page renders its own header, see
 * LocalizedHeader call sites) — cheap, since /notifications is already the
 * same request InboxPage itself makes.
 */
function NotificationBell({ user }: { user: User | null }) {
  const t = useT('common')
  const [hasUnread, setHasUnread] = useState(false)
  const href = accountAreaHref(user, '/admin/inbox')
  const here = typeof window !== 'undefined' ? window.location.pathname : ''

  useEffect(() => {
    let cancelled = false
    listNotifications()
      .then(({ notifications }) => {
        if (!cancelled) setHasUnread(notifications.some((n) => !n.is_read))
      })
      .catch(() => {
        // Silent — a failed unread check is not worth surfacing to the user.
      })
    return () => {
      cancelled = true
    }
  }, [])

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
          className="absolute top-2 right-2 size-2 rounded-full bg-vp-vote-down ring-2 ring-vp-surface animate-vp-stamp"
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
   * the board nav (Board / Roadmap) and, for owners/moderators, adds the
   * board's Settings link — one click from any board page into its admin.
   */
  boardSlug?: string
  /** Admin pages pass their section nav instead of the board nav. */
  navLinks?: HeaderProps['navLinks']
}

export function LocalizedHeader({
  user = null,
  isAuthenticated = false,
  onLogoutClick,
  boardSlug,
  navLinks,
  basePath = '',
  ...props
}: LocalizedHeaderProps) {
  const t = useT('common')

  const role = accountRoleFor(user, getAccountSlug())
  const canModerate = user !== null && (user.is_admin || role === 'owner' || role === 'moderator')

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
      basePath={basePath}
      navLinks={links}
      navExtra={
        <>
          {isAuthenticated && <NotificationBell user={user} />}
          <LanguageSwitcher />
        </>
      }
      account={isAuthenticated ? <AccountMenu user={user} onLogout={onLogoutClick} /> : undefined}
      currentHref={typeof window !== 'undefined' ? window.location.pathname : undefined}
      {...props}
    />
  )
}

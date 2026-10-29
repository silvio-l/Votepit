/**
 * InboxPage — /admin/inbox
 *
 * The in-app notification inbox (migrations/
 * 0024_add_notifications_remove_support_email.sql): shows a "support_reply"
 * notification when the operator answers a ticket (linking back to
 * /admin/support), and every operator-authored "announcement" broadcast.
 * Entirely in-app — replaces the old support-request email channel.
 *
 * Auth gate: any logged-in user (AuthZ: user — not account-scoped, since a
 * user's own memberships already determine what's visible).
 */

import {
  Badge,
  cx,
  EmptyState,
  ErrorState,
  IconButton,
  LoadingState,
  PageHeader,
  Section,
} from '@votepit/ui/ds'
import {
  ArrowUpRight,
  CircleCheck,
  Hourglass,
  Inbox,
  Megaphone,
  MessageCircle,
  MessageSquare,
  Reply,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { useNotificationTranslations } from '../hooks/useIdeaTranslation'
import { accountPath } from '../lib/accountContext'
import type { ApiError, NotificationSummary, NotificationType, User } from '../lib/api'
import {
  bootstrap,
  dismissNotification,
  listNotifications,
  logout,
  markNotificationRead,
} from '../lib/api'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState = { phase: 'loading' } | { phase: 'error'; message: string } | { phase: 'ready' }

// One icon per notification type (notification-preferences feature added
// 'idea_comment'/'thread_reply' alongside the pre-existing support/announcement
// pair) — visually distinguishes at a glance what a row is about.
const NOTIFICATION_ICONS: Record<NotificationType, typeof Megaphone> = {
  announcement: Megaphone,
  support_reply: MessageSquare,
  idea_comment: MessageCircle,
  thread_reply: Reply,
  ideas_pending_approval: Hourglass,
  idea_approved: CircleCheck,
}

export default function InboxPage() {
  const navigate = useNavigate()
  const t = useT('inboxPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [notifications, setNotifications] = useState<NotificationSummary[]>([])
  const [translationAvailable, setTranslationAvailable] = useState(false)
  const [showingOriginal, setShowingOriginal] = useState<Set<number>>(new Set())

  // Not board-bound — only bootstrap()'s translation_available flag gates
  // this (see NotificationAction::translate()'s class doc for why no
  // per-board opt-in applies here).
  const notificationTranslations = useNotificationTranslations(
    notifications.map((n) => n.id),
    language,
    translationAvailable,
  )

  function toggleOriginal(id: number) {
    setShowingOriginal((prev) => {
      const updated = new Set(prev)
      if (updated.has(id)) updated.delete(id)
      else updated.add(id)
      return updated
    })
  }

  function notificationTranslationView(n: NotificationSummary): {
    displayTitle: string
    displayBody: string
    isTranslated: boolean
    failed: boolean
  } {
    const result = notificationTranslations.results.get(n.id)
    if (result === undefined) {
      return { displayTitle: n.title, displayBody: n.body, isTranslated: false, failed: false }
    }
    if (!result.ok) {
      return { displayTitle: n.title, displayBody: n.body, isTranslated: false, failed: true }
    }
    const isTranslated = result.detected_source_locale !== language
    const original = showingOriginal.has(n.id)
    return {
      displayTitle: isTranslated && !original ? (result.translated_title ?? n.title) : n.title,
      displayBody: isTranslated && !original ? result.translated_body : n.body,
      isTranslated,
      failed: false,
    }
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: init is stable (defined inline, no external deps worth tracking); only navigate matters.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/inbox'))}`, {
            replace: true,
          })
          return
        }

        setIsAuthenticated(true)
        setUser(boot.user)
        setTranslationAvailable(boot.translation_available ?? false)

        const data = await listNotifications()
        if (cancelled) return
        setNotifications(data.notifications)

        setPageState({ phase: 'ready' })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/inbox'))}`, {
            replace: true,
          })
          return
        }
        const msg =
          (apiErr as ApiError)?.payload?.message ?? (err as Error)?.message ?? t('loadError')
        setPageState({ phase: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleOpen = async (n: NotificationSummary) => {
    if (!n.is_read) {
      setNotifications((prev) => prev.map((x) => (x.id === n.id ? { ...x, is_read: true } : x)))
      try {
        await markNotificationRead(n.id)
      } catch {
        // non-critical — read state stays optimistic client-side even if this fails
      }
    }
    if (n.link_path) {
      navigate(accountPath(n.link_path))
    }
  }

  const handleDismiss = async (id: number) => {
    setNotifications((prev) => prev.filter((x) => x.id !== id))
    try {
      await dismissNotification(id)
    } catch {
      // non-critical — the row is already gone client-side; a stray retry
      // (e.g. a page reload) will simply see it again and try once more
    }
  }

  const frame = (children: ReactNode) => (
    <AdminShell
      user={user}
      isAuthenticated={isAuthenticated}
      authPending={pageState.phase === 'loading'}
      onLogout={handleLogout}
      onLogin={() => navigate('/login')}
    >
      {children}
    </AdminShell>
  )

  if (pageState.phase === 'loading') {
    return frame(<LoadingState label={t('loading')} rows={5} />)
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title')}
        description={t('subtitle')}
      />

      <Section
        testId="inbox-section"
        title={t('notificationsHeading', { count: notifications.length })}
        icon={<Inbox size={16} />}
        flush
      >
        {notifications.length === 0 ? (
          <EmptyState
            kind="no-ideas"
            variant="plain"
            testId="inbox-empty"
            title={t('noNotifications')}
          />
        ) : (
          <ul
            data-testid="inbox-list"
            className="ds-section-list ds-section-list--bare vp-stagger"
            aria-label={t('notificationsHeading', { count: notifications.length })}
          >
            {notifications.map((n) => {
              const Icon = NOTIFICATION_ICONS[n.type]
              const clickable = n.link_path !== null
              const translation = notificationTranslationView(n)
              return (
                <li key={n.id} className="flex items-stretch animate-vp-fade-in">
                  <div
                    role="button"
                    data-testid={`inbox-item-${n.id}`}
                    tabIndex={clickable || !n.is_read ? 0 : -1}
                    onClick={() => {
                      if (clickable || !n.is_read) void handleOpen(n)
                    }}
                    onKeyDown={(e) => {
                      if ((e.key === 'Enter' || e.key === ' ') && (clickable || !n.is_read)) {
                        e.preventDefault()
                        void handleOpen(n)
                      }
                    }}
                    aria-disabled={!clickable && n.is_read}
                    className={cx(
                      'group flex-1 min-w-0 flex items-start gap-3 ds-section__inset text-left transition-colors duration-ds-fast',
                      !clickable && n.is_read
                        ? 'cursor-default'
                        : 'hover:bg-vp-surface-frost cursor-pointer',
                      !n.is_read && 'bg-vp-accent-soft/40',
                    )}
                  >
                    <span
                      aria-hidden="true"
                      className={cx(
                        'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-vp-md',
                        n.type === 'announcement'
                          ? 'bg-vp-info-soft text-vp-info-strong'
                          : 'bg-vp-accent-soft text-vp-accent-strong',
                      )}
                    >
                      <Icon size={15} />
                    </span>
                    <span className="min-w-0 flex-1 flex flex-col gap-1">
                      <span className="flex flex-wrap items-center justify-between gap-2">
                        <span
                          className={cx(
                            'text-vp-base leading-5 text-vp-title',
                            n.is_read ? 'font-medium' : 'font-semibold',
                          )}
                        >
                          {translation.displayTitle}
                        </span>
                        <span className="flex items-center gap-2">
                          <span className="text-vp-xs text-vp-text-muted font-mono-num">
                            {formatDate(n.created_at, language)}
                          </span>
                          {!n.is_read && (
                            <Badge tone="accent" dot size="sm" testId={`inbox-unread-${n.id}`}>
                              {t('unreadBadge')}
                            </Badge>
                          )}
                        </span>
                      </span>
                      <span className="vp-prose text-vp-sm text-vp-text-secondary">
                        {translation.displayBody}
                      </span>
                      {translation.isTranslated && (
                        <span className="flex items-center gap-1 text-vp-xs text-vp-text-muted">
                          <span>{t('machineTranslatedLabel')}</span>
                          <span aria-hidden="true">·</span>
                          <button
                            type="button"
                            data-testid={`inbox-translation-toggle-${n.id}`}
                            className="underline hover:no-underline"
                            onClick={(e) => {
                              e.stopPropagation()
                              toggleOriginal(n.id)
                            }}
                          >
                            {showingOriginal.has(n.id)
                              ? t('showTranslationLink')
                              : t('showOriginalLink')}
                          </button>
                        </span>
                      )}
                      {translation.failed && (
                        <span className="text-vp-xs text-vp-text-muted">
                          {t('translationUnavailableHint')}
                        </span>
                      )}
                    </span>
                    {clickable && (
                      <ArrowUpRight
                        size={16}
                        aria-hidden="true"
                        className="mt-1 shrink-0 text-vp-text-tertiary transition-colors duration-ds-fast ease-vp-out group-hover:text-vp-title"
                      />
                    )}
                  </div>
                  <IconButton
                    testId={`inbox-dismiss-${n.id}`}
                    icon="close"
                    accessibleLabel={t('dismissAriaLabel', { title: n.title })}
                    onClick={() => void handleDismiss(n.id)}
                    className="shrink-0 self-start m-2"
                  />
                </li>
              )
            })}
          </ul>
        )}
      </Section>
    </>,
  )
}

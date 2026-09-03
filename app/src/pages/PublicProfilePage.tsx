/**
 * PublicProfilePage — /members/:userId/profile
 *
 * Another user's public-safe profile (profile-visibility feature). Readable
 * anonymously — the same trust level as the ideas and comments it is linked
 * from. What is shown depends entirely on what the server sent:
 *
 *   visible: true  → avatar, the 4 fixed social links (only those set)
 *   visible: false → the generic "Voter" placeholder (default for everyone)
 *
 * The owner/moderator badge (`role` — resolved for THIS account) is shown in
 * both cases; it is a property of the account membership, not of the profile.
 * There is no display name anywhere (ADR 0002), so the page title is the
 * neutral role label, never a name.
 */

import {
  buttonClassName,
  Card,
  ErrorState,
  LoadingState,
  PageHeader,
  PageShell,
  SocialIcon,
  type SocialPlatform,
} from '@votepit/ui'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Avatar } from '../components/Avatar'
import { LocalizedHeader, ScopeLabel } from '../components/LocalizedHeader'
import { RoleBadge } from '../components/RoleBadge'
import { accountPath } from '../lib/accountContext'
import type { ApiError, PublicProfile, SocialLinksData, User } from '../lib/api'
import { bootstrap, getPublicProfile, logout } from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'

type LoadState =
  | { phase: 'loading' }
  | { phase: 'not_found' }
  | { phase: 'error' }
  | { phase: 'ready'; profile: PublicProfile }

/**
 * The 4 fixed identifiers → outbound URLs. Values are bare domains/handles
 * validated by SocialLinkValidator on the server (never a URL), so the
 * scheme and host are always ours to choose here — a stored value can't
 * smuggle in a different protocol or host.
 */
const SOCIAL_LINKS: {
  field: keyof SocialLinksData
  platform: SocialPlatform
  href: (value: string) => string
  display: (value: string) => string
}[] = [
  {
    field: 'website_domain',
    platform: 'website',
    href: (v) => `https://${v}`,
    display: (v) => v,
  },
  {
    field: 'x_handle',
    platform: 'x',
    href: (v) => `https://x.com/${encodeURIComponent(v)}`,
    display: (v) => `@${v}`,
  },
  {
    field: 'youtube_handle',
    platform: 'youtube',
    href: (v) => `https://www.youtube.com/@${encodeURIComponent(v)}`,
    display: (v) => `@${v}`,
  },
  {
    field: 'github_username',
    platform: 'github',
    href: (v) => `https://github.com/${encodeURIComponent(v)}`,
    display: (v) => v,
  },
]

function SocialLinkList({ links }: { links: SocialLinksData }) {
  const t = useT('publicProfilePage')
  const present = SOCIAL_LINKS.filter(({ field }) => links[field] !== null)
  if (present.length === 0) return null

  return (
    <ul className="flex flex-wrap items-center gap-2" aria-label={t('socialLinksAriaLabel')}>
      {present.map(({ field, platform, href, display }) => {
        const value = links[field] as string
        return (
          <li key={field}>
            <a
              href={href(value)}
              target="_blank"
              // User-supplied destination: no referrer, no opener, no SEO credit.
              rel="noopener noreferrer nofollow ugc"
              className="inline-flex items-center gap-1.5 rounded-vp-full border border-vp-border-subtle bg-vp-surface px-3 py-1 text-vp-sm text-vp-text-secondary no-underline transition-colors duration-150 hover:border-vp-rule hover:text-vp-ink"
            >
              <SocialIcon platform={platform} size={14} />
              {display(value)}
            </a>
          </li>
        )
      })}
    </ul>
  )
}

export default function PublicProfilePage() {
  const { userId: userIdParam } = useParams<{ userId: string }>()
  const navigate = useNavigate()
  const t = useT('publicProfilePage')
  const tAuthor = useT('authorBadge')
  const { language } = useI18n()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  // A non-numeric or non-positive id can never exist — treat it as not found
  // without a round trip.
  const userId = /^\d+$/.test(userIdParam ?? '') ? Number(userIdParam) : null

  useEffect(() => {
    let cancelled = false
    bootstrap()
      .then((b) => {
        if (cancelled) return
        setIsAuthenticated(b.user !== null)
        setUser(b.user)
      })
      .catch(() => {})
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (userId === null || userId === 0) {
      setLoadState({ phase: 'not_found' })
      return
    }
    let cancelled = false
    setLoadState({ phase: 'loading' })
    getPublicProfile(userId)
      .then((profile) => {
        if (!cancelled) setLoadState({ phase: 'ready', profile })
      })
      .catch((err: unknown) => {
        if (cancelled) return
        const apiErr = err as ApiError
        const notFound = apiErr.name === 'ApiError' && apiErr.status === 404
        setLoadState({ phase: notFound ? 'not_found' : 'error' })
      })
    return () => {
      cancelled = true
    }
  }, [userId])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const homeHref = accountPath('/')
  const header = (
    <LocalizedHeader
      logoHref={homeHref}
      navLinks={[]}
      isAuthenticated={isAuthenticated}
      user={user}
      onLogoutClick={handleLogout}
      onLoginClick={() =>
        navigate(`/login?r=${encodeURIComponent(accountPath(`/members/${userIdParam}/profile`))}`)
      }
      scope={<ScopeLabel section={t('title')} />}
    />
  )

  const backLink = (
    <Link
      to={homeHref}
      className="inline-flex items-center gap-1.5 text-vp-text-secondary hover:text-vp-ink hover:underline transition-colors duration-150"
    >
      <ArrowLeft size={14} strokeWidth={2} aria-hidden="true" />
      {t('backToBoard')}
    </Link>
  )

  if (loadState.phase === 'loading') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <LoadingState label={t('loading')} rows={2} />
      </PageShell>
    )
  }

  if (loadState.phase === 'not_found') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <ErrorState
          // A missing member is a dead end, not a failure that interrupts.
          role="status"
          title={t('notFoundTitle')}
          description={t('notFoundDescription')}
          action={
            <Link to={homeHref} className={buttonClassName('secondary')}>
              <ArrowLeft size={16} strokeWidth={2} aria-hidden="true" />
              {t('backToBoard')}
            </Link>
          }
        />
      </PageShell>
    )
  }

  if (loadState.phase === 'error') {
    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        <ErrorState title={t('errorTitle')} description={t('loadError')} />
      </PageShell>
    )
  }

  const { profile } = loadState

  return (
    <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
      <PageHeader title={t('title')} back={backLink} />

      <Card padding="lg" className="vp-sheet--ruled animate-vp-rise">
        <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:gap-7">
          <Avatar
            avatarUrl={profile.visible ? profile.avatar_url : null}
            size={96}
            alt=""
            className="shrink-0 self-start ring-1 ring-vp-rule shadow-vp-xs"
          />
          <div className="flex min-w-0 flex-1 flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2.5">
              <p className="font-archivo font-bold text-vp-2xl tracking-[-0.02em] text-vp-ink leading-tight">
                {(profile.visible ? profile.username : null) ?? tAuthor('voter')}
              </p>
              {profile.role !== null && <RoleBadge role={profile.role} />}
            </div>

            {profile.visible ? (
              <SocialLinkList links={profile} />
            ) : (
              <p className="text-vp-sm text-vp-text-muted max-w-prose">{t('anonymousHint')}</p>
            )}
          </div>
        </div>
      </Card>
    </PageShell>
  )
}

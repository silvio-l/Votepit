/**
 * IdeaIssueLinksPanel — Linear/GitHub issue links of one idea, inside the
 * idea detail page's moderation strip (owner, admin or moderator).
 *
 * Lists existing links (external key → issue URL) and offers "Create
 * {provider} issue" for every connected provider without a link yet.
 * Renders nothing while loading, on a load error (e.g. a pending idea)
 * and when there is neither a connection nor a link — the connection
 * itself is managed by account admins on the API tokens page.
 */

import { Button, InlineNotice } from '@votepit/ui/ds'
import { ExternalLink } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ApiError, IdeaIssueLinks, IssueTrackerProvider } from '../lib/api'
import { createIdeaIssue, deleteIdeaIssueLink, getIdeaIssueLinks } from '../lib/api'
import { useT } from '../lib/i18n/context'

const ERROR_KEYS: Record<string, string> = {
  issue_link_exists: 'errorLinkExists',
  issue_tracker_not_connected: 'errorNotConnected',
  issue_tracker_invalid_credentials: 'errorInvalidCredentials',
  issue_tracker_rejected: 'errorRejected',
  issue_tracker_unavailable: 'errorUnavailable',
  plan_limit_issue_tracker: 'planUpgradeHint',
}

export function IdeaIssueLinksPanel({ boardSlug, ideaId }: { boardSlug: string; ideaId: number }) {
  const t = useT('issueTrackers')
  const [data, setData] = useState<IdeaIssueLinks | null>(null)
  const [busy, setBusy] = useState<IssueTrackerProvider | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    getIdeaIssueLinks(boardSlug, ideaId)
      .then((res) => {
        if (cancelled) return
        setData(Array.isArray(res.providers) && Array.isArray(res.links) ? res : null)
      })
      .catch(() => {
        if (!cancelled) setData(null)
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug, ideaId])

  if (data === null || (data.providers.length === 0 && data.links.length === 0)) return null

  const providerLabel = (p: IssueTrackerProvider) =>
    p === 'linear' ? t('providerLinear') : t('providerGithub')
  const linked = new Set(data.links.map((l) => l.provider))
  const creatable = data.allowed ? data.providers.filter((p) => !linked.has(p)) : []

  const handleCreate = async (provider: IssueTrackerProvider) => {
    if (busy !== null) return
    setBusy(provider)
    setError(null)
    try {
      await createIdeaIssue(boardSlug, ideaId, provider)
      setData(await getIdeaIssueLinks(boardSlug, ideaId))
    } catch (err) {
      const apiErr = err as ApiError
      const key = apiErr.name === 'ApiError' ? apiErr.payload?.key : undefined
      setError(t(ERROR_KEYS[key ?? ''] ?? 'errorGeneric'))
    } finally {
      setBusy(null)
    }
  }

  const handleUnlink = async (provider: IssueTrackerProvider) => {
    if (busy !== null) return
    setBusy(provider)
    setError(null)
    try {
      await deleteIdeaIssueLink(boardSlug, ideaId, provider)
      setData((prev) =>
        prev === null
          ? prev
          : { ...prev, links: prev.links.filter((l) => l.provider !== provider) },
      )
    } catch {
      setError(t('errorGeneric'))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="mt-3 pt-3 border-t border-vp-rule" role="group" aria-label={t('panelLabel')}>
      <p className="vp-eyebrow mb-2">{t('panelLabel')}</p>
      <div className="flex flex-wrap items-center gap-2">
        {data.links.map((link) => (
          <span key={link.provider} className="inline-flex items-center gap-1.5 text-vp-sm">
            <a
              href={link.url}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 font-mono text-vp-accent hover:underline"
            >
              {providerLabel(link.provider)} {link.external_key}
              <ExternalLink size={12} aria-hidden="true" />
            </a>
            <Button
              variant="text"
              testId={`idea-issue-unlink-${link.provider}`}
              onClick={() => void handleUnlink(link.provider)}
              disabled={busy !== null}
              aria-label={t('unlinkAriaLabel', { key: link.external_key })}
            >
              {t('unlink')}
            </Button>
          </span>
        ))}
        {creatable.map((provider) => (
          <Button
            key={provider}
            variant="secondary"
            testId={`idea-issue-create-${provider}`}
            onClick={() => void handleCreate(provider)}
            disabled={busy !== null}
            loading={busy === provider}
          >
            {t('createIssue', { provider: providerLabel(provider) })}
          </Button>
        ))}
      </div>
      {error !== null && (
        <InlineNotice tone="error" className="mt-2">
          {error}
        </InlineNotice>
      )}
    </div>
  )
}

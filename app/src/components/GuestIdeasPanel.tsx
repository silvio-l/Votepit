/**
 * GuestIdeasPanel — "My ideas on this device" for visitors who submitted
 * without an account (ADR 0006 §7). The device is recognised only by its
 * `vp_anon_author` cookie, so the list is per browser, not per person.
 *
 * Waiting ideas can be withdrawn (hard delete); published ones link to the
 * idea; removed ones show the moderator's reason (DSA Art. 17 statement of
 * reasons, as far as possible without contact details). Renders nothing
 * while the device has no guest ideas on the board.
 */

import { Badge, Button, InlineNotice, Section } from '@votepit/ui/ds'
import { Inbox } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import type { GuestIdea } from '../lib/api'
import { getGuestIdeas, withdrawGuestIdea } from '../lib/api'
import { useT } from '../lib/i18n/context'

const REASON_LABEL_KEYS: Record<string, string> = {
  community_rule: 'commentModeration.reasonCommunityRule',
  illegal_copyright: 'commentModeration.illegalCopyright',
  illegal_privacy: 'commentModeration.illegalPrivacy',
  illegal_threat: 'commentModeration.illegalThreat',
  illegal_fraud: 'commentModeration.illegalFraud',
  illegal_other: 'commentModeration.illegalOther',
}

interface GuestIdeasPanelProps {
  boardSlug: string
  /** Bump to reload (e.g. right after a successful submission). */
  refreshKey?: number
}

export function GuestIdeasPanel({ boardSlug, refreshKey = 0 }: GuestIdeasPanelProps) {
  const t = useT('submitPage')
  const tCommon = useT('common')
  const [ideas, setIdeas] = useState<GuestIdea[]>([])
  const [busyId, setBusyId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      const res = await getGuestIdeas(boardSlug)
      setIdeas(Array.isArray(res.ideas) ? res.ideas : [])
    } catch {
      // Non-critical: no list rather than an error page.
      setIdeas([])
    }
  }, [boardSlug])

  // biome-ignore lint/correctness/useExhaustiveDependencies: refreshKey intentionally triggers a reload.
  useEffect(() => {
    void load()
  }, [load, refreshKey])

  const handleWithdraw = async (id: number) => {
    setBusyId(id)
    setError(null)
    try {
      await withdrawGuestIdea(boardSlug, id)
      await load()
    } catch {
      setError(t('guestWithdrawFailed'))
    } finally {
      setBusyId(null)
    }
  }

  if (ideas.length === 0) return null

  return (
    <Section
      testId="guest-ideas-section"
      title={t('guestMineHeading')}
      icon={<Inbox size={16} />}
      emphasis="ruled"
    >
      <p className="text-vp-sm text-vp-text-secondary text-pretty">{t('guestMineLead')}</p>
      {error !== null && (
        <InlineNotice tone="error" className="mt-3">
          {error}
        </InlineNotice>
      )}
      <ul className="mt-3 flex flex-col divide-y divide-vp-border-subtle">
        {ideas.map((idea) => (
          <li key={idea.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
            <div className="min-w-0 flex flex-col gap-1">
              {idea.moderation_state === 'published' && !idea.rejected ? (
                <Link
                  to={accountPath(`/${boardSlug}/idea/${idea.id}`)}
                  className="font-medium text-vp-title underline hover:no-underline break-words"
                >
                  {idea.title}
                </Link>
              ) : (
                <span className="font-medium text-vp-title break-words">{idea.title}</span>
              )}
              {idea.rejected && (
                <span className="text-vp-xs text-vp-text-muted">
                  {t('guestRejectedReason', {
                    reason: tCommon(
                      REASON_LABEL_KEYS[idea.reason_code ?? ''] ?? 'commentModeration.reasonOther',
                    ),
                  })}
                </span>
              )}
            </div>
            <span className="inline-flex items-center gap-2 shrink-0">
              {idea.rejected ? (
                <Badge tone="danger">{t('guestStateRejected')}</Badge>
              ) : idea.moderation_state === 'pending' ? (
                <>
                  <Badge tone="warning">{t('guestStatePending')}</Badge>
                  <Button
                    variant="text-destructive"
                    testId={`guest-idea-withdraw-${idea.id}`}
                    onClick={() => void handleWithdraw(idea.id)}
                    disabled={busyId !== null}
                    loading={busyId === idea.id}
                  >
                    {t('guestWithdraw')}
                  </Button>
                </>
              ) : (
                <Badge tone="success">{t('guestStatePublished')}</Badge>
              )}
            </span>
          </li>
        ))}
      </ul>
    </Section>
  )
}

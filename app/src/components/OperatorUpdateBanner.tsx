/**
 * OperatorUpdateBanner — advisory notice on OperatorPage when a newer
 * Community release is known. Self-contained: loads its own status on mount.
 * Advisory only: any load failure renders nothing, and the banner never
 * blocks or errors the surrounding page.
 */

import { InlineNotice } from '@votepit/ui/ds'
import { ExternalLink } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { OperatorUpdateStatus } from '../lib/api'
import { getOperatorUpdateStatus } from '../lib/api'
import { useT } from '../lib/i18n/context'

export function OperatorUpdateBanner() {
  const t = useT('operatorPage')
  const [status, setStatus] = useState<OperatorUpdateStatus | null>(null)

  useEffect(() => {
    let cancelled = false
    getOperatorUpdateStatus()
      .then((data) => {
        if (!cancelled) setStatus(data)
      })
      .catch(() => {
        if (!cancelled) setStatus(null)
      })
    return () => {
      cancelled = true
    }
  }, [])

  if (status === null || !status.update_available || status.latest_version === null) {
    return null
  }

  const linkClass =
    'inline-flex items-center gap-1 text-vp-accent font-medium underline underline-offset-[0.2em] hover:text-vp-title'

  return (
    <InlineNotice
      tone="info"
      role="status"
      testId="operator-update-banner"
      title={t('updateBannerHeading')}
    >
      <p>
        {t('updateBannerBody', {
          latest: status.latest_version,
          installed: status.installed_version,
        })}
      </p>
      <p className="flex flex-wrap gap-x-4 gap-y-1">
        {status.release_url !== null && (
          <a
            href={status.release_url}
            target="_blank"
            rel="noopener noreferrer"
            data-testid="operator-update-release-link"
            className={linkClass}
          >
            {t('updateBannerReleaseNotes')}
            <ExternalLink size={12} aria-hidden="true" />
          </a>
        )}
        {status.docs_url !== null && (
          <a
            href={status.docs_url}
            target="_blank"
            rel="noopener noreferrer"
            data-testid="operator-update-docs-link"
            className={linkClass}
          >
            {t('updateBannerUpgradeGuide')}
            <ExternalLink size={12} aria-hidden="true" />
          </a>
        )}
      </p>
    </InlineNotice>
  )
}

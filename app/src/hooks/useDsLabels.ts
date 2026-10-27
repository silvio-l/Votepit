import type { IdeaStatus } from '@votepit/ui/ds'
import { useMemo } from 'react'
import { useT } from '../lib/i18n/context'

/**
 * Localized texts for DS components that have no label of their own: the unread
 * badge, the pending state of an idea card and the status chip wording.
 */
export function useDsLabels() {
  const t = useT('dsLabels')
  return useMemo(
    () => ({
      unread: t('unread'),
      pending: t('pending'),
      statusLabels: {
        open: t('status.open'),
        'in-review': t('status.inReview'),
        planned: t('status.planned'),
        'in-progress': t('status.inProgress'),
        done: t('status.done'),
        declined: t('status.declined'),
        'not-implemented': t('status.declined'),
        archived: t('status.archived'),
      } satisfies Record<IdeaStatus, string>,
    }),
    [t],
  )
}

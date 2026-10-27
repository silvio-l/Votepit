/**
 * BoardOperatorFooter — legal board-operator disclosure (board-operator-
 * compliance issue 03): "Operated by {name}", plus a low-key link to the
 * dedicated public legal-notice page (ImpressumPage, international-Impressum
 * research 2026-09-09 — same pattern as Shopify's own linked Legal page
 * rather than harvestable contact details inlined in every footer). Shown
 * alongside (never instead of) the existing "Powered by Votepit" line.
 *
 * The full address deliberately stays off this footer and appears only on
 * ImpressumPage itself — this line just names who to hold accountable and
 * points at where the full disclosure lives.
 *
 * Deliberately NOT gated behind the Cloud/Community edition badge logic
 * (`core/app/src/lib/edition.ts`) or the `show_badge` plan-tier flag that
 * gates "Powered by Votepit" — those answer "which software edition/plan is
 * this," this answers "who legally operates this board," a different
 * question (PRD "Board-operator branding", user story 10). Renders nothing
 * when `legalOperatorName` is empty/null — a pre-gate legacy board (see
 * BoardHomeAction's class doc) simply shows no operator line rather than an
 * empty one.
 */

import { Link } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import { useT } from '../lib/i18n/context'

interface BoardOperatorFooterProps {
  boardSlug: string
  legalOperatorName: string | null
}

export function BoardOperatorFooter({ boardSlug, legalOperatorName }: BoardOperatorFooterProps) {
  const t = useT('boardPage')

  if (!legalOperatorName) return null

  return (
    <p className="text-center text-vp-xs text-vp-text-muted">
      {t('operatedBy', { name: legalOperatorName })}
      <Link
        to={accountPath(`/${boardSlug}/impressum`)}
        className="block underline decoration-vp-underline hover:text-vp-title"
      >
        {t('legalNoticeLink')}
      </Link>
    </p>
  )
}

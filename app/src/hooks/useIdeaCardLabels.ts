import type { VoteControlLabels, VoteSummaryLabels } from '@votepit/ui/ds'
import type { BrandingPro } from '../lib/branding'
import { intlLocale } from '../lib/formatDate'
import { wordingOverride } from '../lib/i18n/boardWording'
import { useI18n, useT } from '../lib/i18n/context'

/**
 * Localized texts for the DS idea card and its vote controls. Every screen that
 * renders idea cards takes its texts here, so the wording stays identical across
 * board and roadmap. `voteReason` replaces the vote hint while voting is closed.
 */
export function useIdeaCardLabels(voteReason: string | null, brandingPro?: BrandingPro | null) {
  const tCommon = useT('common')
  const { language } = useI18n()
  // Owner wording (Pro) for the For/Against words, per locale; Votepit's own text otherwise.
  const forWord = wordingOverride(brandingPro?.wording, language, 'vote_for')
  const againstWord = wordingOverride(brandingPro?.wording, language, 'vote_against')
  const upLabel = forWord ?? tCommon('vote.upLabel')
  const downLabel = againstWord ?? tCommon('vote.downLabel')

  const voteLabels: VoteControlLabels = {
    up: { verb: forWord ?? tCommon('vote.upAriaLabel'), label: upLabel },
    down: { verb: againstWord ?? tCommon('vote.downAriaLabel'), label: downLabel },
    votesSingular: tCommon('vote.votesSingular'),
    votesPlural: tCommon('vote.votesPlural'),
    error: tCommon('vote.saveError'),
    readonly: voteReason ?? tCommon('vote.readonlyHint'),
  }
  const summaryLabels: VoteSummaryLabels = {
    up: upLabel,
    down: downLabel,
    net: tCommon('vote.netLabel'),
    approval: tCommon('vote.consensusLabel'),
    empty: tCommon('vote.consensusEmptyLabel'),
    explanation: tCommon('vote.approvalExplain'),
    fewVotes: tCommon('vote.fewVotesLabel'),
  }
  return {
    locale: intlLocale(language),
    voteLabels,
    summaryLabels,
    commentLabel: (count: number) =>
      `${count} ${count === 1 ? tCommon('idea.commentLabel') : tCommon('idea.commentsLabel')}`,
  }
}

// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=71-69
// source=src/components/VoteWidget.tsx
// component=VoteWidget
import figma from 'figma'

const instance = figma.selectedInstance

// Figma variant property: Tone (leading | neutral)
const tone = instance.getEnum('Tone', {
  leading: 'leading',
  neutral: 'neutral',
})

export default {
  example: figma.code`<VoteWidget tone="${tone}" score={42} onVoteUp={() => {}} onVoteDown={() => {}} />`,
  imports: ['import { VoteWidget } from "@votepit/ui"'],
  id: 'vote-widget',
  metadata: { nestable: true },
}

// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=71-69
// source=src/components/VoteWidget.tsx
// component=VoteWidget
import figma from 'figma'
const instance = figma.selectedInstance

const state = instance.getEnum('State', {
  'Neutral': 'neutral',
  'Upvoted': 'up',
  'Downvoted': 'down',
})
const score = instance.getString('Score')

export default {
  example: figma.code`<VoteWidget state="${state}" score="${score}" onUp={() => {}} onDown={() => {}} />`,
  imports: ['import { VoteWidget } from "@votepit/components"'],
  id: 'vote-widget',
  metadata: { nestable: true },
}

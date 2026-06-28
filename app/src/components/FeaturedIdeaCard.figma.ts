// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=69-85
// source=src/components/FeaturedIdeaCard.tsx
// component=FeaturedIdeaCard
import figma from 'figma'
const instance = figma.selectedInstance

const title = instance.getString('Title')
const body = instance.getString('Body')

const voteWidget = instance.findInstance('Vote Widget')
let voteCode
if (voteWidget && voteWidget.type === 'INSTANCE') {
  voteCode = voteWidget.executeTemplate().example
}

const consensusBar = instance.findInstance('Consensus Bar')
let consensusCode
if (consensusBar && consensusBar.type === 'INSTANCE') {
  consensusCode = consensusBar.executeTemplate().example
}

export default {
  example: figma.code`
    <FeaturedIdeaCard
      title="${title}"
      body="${body}"
      voteWidget={${voteCode}}
      consensusBar={${consensusCode}}
    />`,
  imports: ['import { FeaturedIdeaCard } from "@votepit/components"'],
  id: 'featured-idea-card',
  metadata: { nestable: true },
}

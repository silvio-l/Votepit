// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=69-85
// source=src/components/FeaturedIdeaCard.tsx
// component=FeaturedIdeaCard
import figma from 'figma'

const instance = figma.selectedInstance

// Figma TEXT properties confirmed: Title, Description
const title = instance.getString('Title')
const description = instance.getString('Description')

export default {
  example: figma.code`
    <FeaturedIdeaCard
      title="${title}"
      description="${description}"
      status="open"
      score={128}
      commentCount={24}
      consensusPercent={82}
      weeklyVotes={312}
      weeklyNewIdeas={18}
      avgConsensusPercent={92}
    />`,
  imports: ['import { FeaturedIdeaCard } from "@votepit/components"'],
  id: 'featured-idea-card',
  metadata: { nestable: true },
}

// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=74-87
// source=src/components/IdeaListRow.tsx
// component=IdeaListRow
import figma from 'figma'

const instance = figma.selectedInstance

// Figma TEXT properties confirmed: Title, Excerpt
const title = instance.getString('Title')
const excerpt = instance.getString('Excerpt')

export default {
  example: figma.code`
    <IdeaListRow
      id={1}
      title="${title}"
      excerpt="${excerpt}"
      status="open"
      score={42}
      commentCount={12}
      timeAgo="vor 3 Tagen"
      consensusPercent={82}
    />`,
  imports: ['import { IdeaListRow } from "@votepit/ui"'],
  id: 'idea-list-row',
  metadata: { nestable: true },
}

// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=74-87
// source=src/components/IdeaListRow.tsx
// component=IdeaListRow
import figma from 'figma'
const instance = figma.selectedInstance

const title = instance.getString('Title')
const commentCount = instance.getString('CommentCount')

const statusBadge = instance.findInstance('Status Badge')
let statusCode
if (statusBadge && statusBadge.type === 'INSTANCE') {
  statusCode = statusBadge.executeTemplate().example
}

const voteWidget = instance.findInstance('Vote Widget')
let voteCode
if (voteWidget && voteWidget.type === 'INSTANCE') {
  voteCode = voteWidget.executeTemplate().example
}

export default {
  example: figma.code`
    <IdeaListRow
      title="${title}"
      commentCount={${commentCount.split(' ')[0]}}
      statusBadge={${statusCode}}
      voteWidget={${voteCode}}
    />`,
  imports: ['import { IdeaListRow } from "@votepit/components"'],
  id: 'idea-list-row',
  metadata: { nestable: true },
}

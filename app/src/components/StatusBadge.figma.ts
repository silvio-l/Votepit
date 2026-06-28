// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=61-47
// source=src/components/StatusBadge.tsx
// component=StatusBadge
import figma from 'figma'
const instance = figma.selectedInstance

const status = instance.getEnum('Status', {
  'Open': 'open',
  'Planned': 'planned',
  'In Progress': 'in_progress',
  'Done': 'done',
  'Declined': 'declined',
})

export default {
  example: figma.code`<StatusBadge status="${status}" />`,
  imports: ['import { StatusBadge } from "@votepit/components"'],
  id: 'status-badge',
  metadata: { nestable: true },
}

// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=61-47
// source=src/components/StatusBadge.tsx
// component=StatusBadge
import figma from 'figma'

const instance = figma.selectedInstance

// Figma variant axis: status — values are lowercase hyphenated.
// Note: label is baked per variant in Figma (no TEXT property).
const status = instance.getEnum('Status', {
  open: 'open',
  planned: 'planned',
  'in-progress': 'in-progress',
  done: 'done',
  declined: 'declined',
})

export default {
  example: figma.code`<StatusBadge status="${status}" />`,
  imports: ['import { StatusBadge } from "@votepit/ui"'],
  id: 'status-badge',
  metadata: { nestable: true },
}

// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=67-45
// source=src/components/SortTabs.tsx
// component=SortTabs
import figma from 'figma'
const instance = figma.selectedInstance

const active = instance.getEnum('Active Tab', {
  'Top': 'top',
  'Newest': 'newest',
  'Controversial': 'controversial',
})

export default {
  example: figma.code`<SortTabs active="${active}" onChange={(tab) => {}} />`,
  imports: ['import { SortTabs } from "@votepit/components"'],
  id: 'sort-tabs',
  metadata: { nestable: true },
}

// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=63-32
// source=src/components/ConsensusBar.tsx
// component=ConsensusBar
import figma from 'figma'

const instance = figma.selectedInstance

const pct = instance.getString('Percentage')
const low = instance.getBoolean('Is Contested')

export default {
  example: figma.code`<ConsensusBar percentage={${pct.split('%')[0]}} isContested={${low}} />`,
  imports: ['import { ConsensusBar } from "@votepit/components"'],
  id: 'consensus-bar',
  metadata: { nestable: true },
}

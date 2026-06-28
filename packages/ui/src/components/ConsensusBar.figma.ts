// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=63-32
// source=src/components/ConsensusBar.tsx
// component=ConsensusBar
import figma from 'figma'

// ConsensusBar is a static Figma component with no TEXT/VARIANT properties.
// The percent prop drives the fill width and label ("Konsens" vs "Umstritten").
export default {
  example: figma.code`<ConsensusBar percent={82} />`,
  imports: ['import { ConsensusBar } from "@votepit/components"'],
  id: 'consensus-bar',
  metadata: { nestable: true },
}

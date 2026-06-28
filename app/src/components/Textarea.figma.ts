// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=91-43
// source=src/components/Textarea.tsx
// component=Textarea
import figma from 'figma'
const instance = figma.selectedInstance

const placeholder = instance.getString('Placeholder')
const label = instance.getString('Label')

export default {
  example: figma.code`<Textarea label="${label}" placeholder="${placeholder}" />`,
  imports: ['import { Textarea } from "@votepit/components"'],
  id: 'textarea',
  metadata: { nestable: true },
}

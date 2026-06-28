// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=60-42
// source=src/components/TextInput.tsx
// component=TextInput
import figma from 'figma'
const instance = figma.selectedInstance

const placeholder = instance.getString('Placeholder')
const label = instance.getString('Label')
const hasError = instance.getBoolean('Has Error')

export default {
  example: figma.code`<TextInput label="${label}" placeholder="${placeholder}" hasError={${hasError}} />`,
  imports: ['import { TextInput } from "@votepit/components"'],
  id: 'text-input',
  metadata: { nestable: true },
}

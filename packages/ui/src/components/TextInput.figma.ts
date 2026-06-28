// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=60-42
// source=src/components/TextInput.tsx
// component=TextInput
import figma from 'figma'

const instance = figma.selectedInstance

const label = instance.getString('Label')
const placeholder = instance.getString('Placeholder')
// Figma VARIANT: State = default | error (maps to optional `error` string prop)
const isError = instance.getEnum('State', {
  default: false,
  error: true,
})

export default {
  example: figma.code`<TextInput label="${label}" placeholder="${placeholder}" value="" onChange={() => {}} ${isError ? 'error="Ungültige Eingabe"' : ''} />`,
  imports: ['import { TextInput } from "@votepit/components"'],
  id: 'text-input',
  metadata: { nestable: true },
}

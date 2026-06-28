// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=91-43
// source=src/components/Textarea.tsx
// component=Textarea
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
  example: figma.code`<Textarea label="${label}" placeholder="${placeholder}" value="" onChange={() => {}} ${isError ? 'error="Pflichtfeld"' : ''} />`,
  imports: ['import { Textarea } from "@votepit/components"'],
  id: 'textarea',
  metadata: { nestable: true },
}

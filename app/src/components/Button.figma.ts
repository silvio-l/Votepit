// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=59-38
// source=src/components/Button.tsx
// component=Button
import figma from 'figma'

const instance = figma.selectedInstance

const label = instance.getString('Label')
const variant = instance.getEnum('Variant', {
  Primary: 'primary',
  Secondary: 'secondary',
  Ghost: 'ghost',
})

export default {
  example: figma.code`<Button variant="${variant}">${label}</Button>`,
  imports: ['import { Button } from "@votepit/components"'],
  id: 'button',
  metadata: { nestable: true },
}

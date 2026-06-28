// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=66-32
// source=src/components/Header.tsx
// component=Header
import figma from 'figma'
const instance = figma.selectedInstance

const boardName = instance.getString('Board Name')
const isLoggedIn = instance.getBoolean('Is Logged In')

export default {
  example: figma.code`<Header boardName="${boardName}" isLoggedIn={${isLoggedIn}} />`,
  imports: ['import { Header } from "@votepit/components"'],
  id: 'header',
  metadata: { nestable: false },
}

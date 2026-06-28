// url=https://www.figma.com/design/LF4w4ib8q7m8EAemr0P4k6/Votepit?node-id=66-32
// source=src/components/Header.tsx
// component=Header
import figma from 'figma'

// Header is a static Figma component with no TEXT/VARIANT properties.
// Real props are shown below as a usage example.
export default {
  example: figma.code`<Header loginLabel="Anmelden" isAuthenticated={false} onLoginClick={() => {}} />`,
  imports: ['import { Header } from "@votepit/ui"'],
  id: 'header',
  metadata: { nestable: false },
}

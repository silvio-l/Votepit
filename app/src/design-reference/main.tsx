import { createRoot } from 'react-dom/client'
import '@votepit/ui/ds/tokens.css'
import '@votepit/ui/ds/components.css'
import './reference.css'
import { DesignReference } from './DesignReference'

const rootElement = document.getElementById('root')
if (!rootElement) throw new Error('Root element #root not found')

createRoot(rootElement).render(<DesignReference />)

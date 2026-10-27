import { NotFoundState } from '../components/NotFoundState'

/** Global catch-all (unknown route, any depth). Keeps its route and HTTP behaviour; only the page is shared. */
export default function NotFoundPage() {
  return <NotFoundState />
}

import type { ComponentPropsWithoutRef } from 'react'
import { Link } from 'react-router-dom'

/**
 * Router adapter for the DS `ButtonLink` (`linkAs`): ButtonLink passes `href`,
 * react-router's `Link` takes `to`.
 */
export function RouterButtonLink({
  href,
  ...props
}: ComponentPropsWithoutRef<'a'> & { href: string }) {
  return <Link to={href} {...props} />
}

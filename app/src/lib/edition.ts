/**
 * Which edition this running instance is: 'community' (self-host, single
 * account) or 'cloud' (multi-tenant hosted instance). Same module-level-state
 * pattern as accountContext.ts's currentAccountSlug — set once from
 * AppRoutes's bootstrap() call, read anywhere (including outside React, e.g.
 * LocalizedHeader) without threading it through every prop chain.
 *
 * Derived 1:1 from Config::routingMode (core/src/Config.php) by default —
 * self-host installs are Community edition, routing_mode: cloud is the Cloud
 * edition. An extension may override this default via the bootstrap
 * `product_edition` feature (see PublicDemoExtension): the public demo runs
 * with routing_mode 'self-host' (single-tenant isolation, hourly wipe) but
 * represents the Cloud product, so it declares `product_edition: 'cloud'` to
 * show the Cloud badge without changing its tenancy/routing behaviour. The
 * SPA codebase itself stays edition-neutral (CLAUDE.md) — this only decides
 * which label a shared component renders, not which code runs.
 */

export type Edition = 'community' | 'cloud'

let currentEdition: Edition = 'community'

export function setEdition(routingMode: 'self-host' | 'cloud', override?: Edition): void {
  currentEdition = override ?? (routingMode === 'cloud' ? 'cloud' : 'community')
}

export function getEdition(): Edition {
  return currentEdition
}

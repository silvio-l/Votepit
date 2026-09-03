/**
 * Which edition this running instance is: 'community' (self-host, single
 * account) or 'cloud' (multi-tenant hosted instance). Same module-level-state
 * pattern as accountContext.ts's currentAccountSlug — set once from
 * AppRoutes's bootstrap() call, read anywhere (including outside React, e.g.
 * LocalizedHeader) without threading it through every prop chain.
 *
 * Derived 1:1 from Config::routingMode (core/src/Config.php) — self-host
 * installs are always Community edition, routing_mode: cloud is always the
 * Cloud edition; there is no separate "edition" config key to keep in sync.
 * The SPA codebase itself stays edition-neutral (CLAUDE.md) — this only
 * decides which label a shared component renders, not which code runs.
 */

export type Edition = 'community' | 'cloud'

let currentEdition: Edition = 'community'

export function setEdition(routingMode: 'self-host' | 'cloud'): void {
  currentEdition = routingMode === 'cloud' ? 'cloud' : 'community'
}

export function getEdition(): Edition {
  return currentEdition
}

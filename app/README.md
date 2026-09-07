# Votepit App (React SPA)

React 19 + Vite + TypeScript + Tailwind frontend for Votepit.

## Commands

```bash
pnpm dev          # Vite dev server (port 5173, proxies /api → PHP :8080)
pnpm build        # tsc -b && vite build → dist/
pnpm lint         # oxlint
pnpm test         # vitest run (all tests, one-shot)
pnpm test:watch   # vitest (interactive watch)
pnpm preview      # preview the production build
```

## Testing conventions

Stack: **Vitest + React Testing Library** (`vitest.config.ts`, jsdom environment).

What gets unit-tested:
- **Stateful interactive widgets** whose logic can go wrong without rendering the full app — e.g. VoteWidget (optimistic state), SortTabs (selection), Submit form (validation feedback). Test user-visible behaviour: what appears on screen, what happens on click — never React internals.

What does NOT get unit-tested:
- Pure display/layout components — covered by the screenshot Design Gate instead.
- Page-level routing and data-fetching — integration concern, out of scope for unit tests.

Test file location: co-located (`*.test.tsx`) or under `src/__tests__/`. Setup file: `src/test-setup.ts` (registers `@testing-library/jest-dom` matchers via `expect.extend`).

Guiding rule: keep the test count low and intentional. Every test must justify its existence by checking behaviour a user actually cares about.

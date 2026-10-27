# Votepit Design System: spacing, radius, dividers, focus

Source of truth: `tokens/tokens.json` (generated into `src/ds/tokens.generated.css`,
`theme.generated.css`, `tokens.generated.ts`). This file is canonical for spacing, radius, dividers and focus;
layout rules for a particular page are not part of this package. Components in `src/ds/components.css` use
only `var(--vpds-…)` for spacing, radius and gaps. The lint in `tokens/lint-spacing.mjs`
enforces that; it runs as part of `node --test tokens/build-tokens.test.mjs`.

## Spacing scale (`semantic.space.*`)

| Token | px | Use for |
|---|---|---|
| `2xs` | 4 | Icon-to-label nudges, dense chip internals |
| `xs` | 8 | Gap inside a control group (chips, vote buttons), icon-to-text in a row |
| `sm` | 12 | Padding inside compact controls, gap between stacked fields and labels |
| `md` | 16 | Default card-internal gap between stacked blocks, mobile card padding |
| `lg` | 24 | Card padding (desktop), gap between card sections |
| `xl` | 32 | Gap between groups within a page section |
| `2xl` | 48 | Gap between page-level blocks |
| `3xl` | 64 | Separation before a full-width band |
| `gutter` | fluid 16 → 64 | Page side padding (375 px → 2560 px viewport) |
| `section` | fluid 56 → 144 | Vertical rhythm between page sections |
| `grid-gap` | fluid 16 → 40 | Gap between cards in a grid |

Rules:
- Relation first, token second: a gap between stacked siblings uses one of the scale steps;
  never a literal value. Pick the smallest step that separates the groups visibly.
- Card padding is `lg` on desktop and `md` on small screens, identical across idea, decision and menu surfaces.
- Controls (buttons, chips, vote controls) get their padding from `sm`/`xs`, so their heights
  come from the control tokens, not from ad-hoc paddings.
- Nothing touches a card edge: content inside a card keeps at least `md` from the border.
- Full-width bands (topbar, hero, footer) run edge to edge; their inner content uses `gutter`.
- Page sections are separated by `section`; blocks inside a section by `2xl`/`xl`.
- Text never sets the height of its box from the outside: a container with text that can
  wrap uses `min-height` plus block padding, never a fixed `height`, so a second line grows
  the box instead of eating its top and bottom spacing. A fixed height is only for text that
  must stay on one line (`white-space: nowrap`) and for square glyph boxes (avatar initials,
  step markers). Enforced by `tokens/lint-text-height.mjs` (quality gate).

## Radius (`semantic.radius.*`)

`control` (10 px) for buttons, fields and media; `card` (16 px) for cards and menus;
`section` (24 px) for large surfaces; `pill` for chips, vote controls and status tags.

## Dividers and borders

- `border.subtle` is the divider between list rows and the default card outline.
- `border.control` is the outline of form controls and vote controls.
- `border.inverse` is used on forest/dark surfaces only.
- Border width comes from `control-border-width` (1 px hairline). A raw `1px` border width is allowed
  by the lint; any other raw spacing or radius value is rejected.
- No shared list-row divider rule exists in the DS yet: rows that need one should use `border.subtle`
  and not introduce a new colour.

## Focus

- Focus rings use `focus-width` and `focus-offset` with `focus-ring` (or `focus-halo` on inverse surfaces).
- Focus rings must not be clipped. `overflow: hidden` is only set on elements that contain no
  focusable descendants (`.ds-visually-hidden`, `.ds-idea-card__media`). Do not add it to containers of
  links or buttons.

## Motion (`semantic.motion.*`)

Motion only explains a state change; it never decorates. Every duration and curve comes from the tokens
below (generated to `--vpds-motion-*`, and to Tailwind as `duration-ds-*`, `ease-ds-easing`; bare
`transition` uses the fast token by default). Nothing else is written as a time or curve.

| Token (CSS var) | Value | Use for |
|---|---|---|
| `semantic.motion.instant` (`--vpds-motion-instant`) | 0 ms | Explicit "no motion" |
| `semantic.motion.fast` (`--vpds-motion-fast`) | 120 ms | Hover, press, colour and border changes, chip and filter state |
| `semantic.motion.normal` (`--vpds-motion-normal`) | 180 ms | Fades, the vote count roll, tab underline, small arrivals |
| `semantic.motion.emphasis` (`--vpds-motion-emphasis`) | 240 ms | Dialog and panel entrances, the vote tick, sheets. **Maximum** |
| `semantic.motion.stagger` (`--vpds-motion-stagger`) | 30 ms | Delay step between list items arriving in turn |
| `semantic.motion.celebration` (`--vpds-motion-celebration`) | 1000 ms | **Exception.** Referral-reward confetti rain (`Celebration`, `effect="confetti"`) only |
| `semantic.motion.celebration-sparkle` (`--vpds-motion-celebration-sparkle`) | 700 ms | **Exception.** Board-milestone sparkle pop (`Celebration`, `effect="sparkle"`) only |
| `semantic.motion.easing` (`--vpds-motion-easing`) | cubic-bezier(0.2, 0, 0, 1) | The one curve for everything |

Rules:
- **Maximum 240 ms** per animation (no delay chains longer than the max either). Above it, the
  motion is a bug.
- **Documented exception: the celebration overlay.** The two `celebration` tokens are the only
  durations above the max. The reward moment may celebrate longer (owner decision 24b): confetti 1000 ms,
  sparkle 700 ms, each on its own `Celebration` keyframes (`--animate-vp-confetti-fall`,
  `--animate-vp-sparkle-pop` in `src/tokens.css`). `tokens/lint-motion.mjs` rejects them on any other
  property, and `build-tokens.test.mjs` fails if any other motion token exceeds 240 ms. They never run under
  reduced motion: `Celebration` mounts no overlay, and the tokens also resolve to 0 in the reduce block.
- **Transform and opacity only.** Never animate width, height, top, left or margin: no layout shift.
- **No loops.** Nothing auto-plays in a repeat. The one exception is the loading spinner (`ds-spin`,
  marked `lint-allow`), which stops under reduced motion. The live dot is static.
- **Entrances only.** Dialogs, panels and sheets fade and scale or rise in. Exits are not animated; the
  element unmounts at once. The one exception is the toast: it fades out on `semantic.motion.fast`
  before `onClose` unmounts it, and closes at once under reduced motion. Entrance rules sit under
  `prefers-reduced-motion: no-preference`.
- **Reduced motion.** `semantic.motion.fast`, `normal`, `emphasis` and `stagger` alias
  `instant` under `prefers-reduced-motion: reduce` in `tokens.generated.css`, so every token-based
  duration drops to 0 without a per-component rule. JS-driven motion (FLIP reorder, the celebration
  overlay) checks `prefersReducedMotion()` from `@votepit/ui/ds/motion` and does nothing when it is set.
  The global rule in `src/tokens.css` is only a backstop.
- **Lint.** `tokens/lint-motion.mjs` rejects raw time literals, raw curves, easing keywords and
  infinite animations in the DS and app stylesheets and in TSX duration classes (see
  `tokens/lint-motion.test.mjs`). Functional timers (the toast auto-dismiss bar) carry a `lint-allow`
  reason.

## Dark theme

Every semantic colour and shadow role (`semantic.*`) carries a dark value in its
`com.votepit.dark` extension: an alias to a `primitive.color.night.*` (or brand) colour, or a literal
shadow. The generator emits the dark values twice: on `[data-theme="dark"]` (explicit choice) and under
`@media (prefers-color-scheme: dark)` guarded by `:root:not([data-theme="light"])` (system default). Because
both selectors match `<html>`, every `:root` alias (`--color-vp-*`, `--vp-*`) recomputes with them. A
wrapper element can only switch the theme for descendants by re-declaring those aliases, so theme choice
is made on the document element.

- Light values stay the defaults; dark never changes a light value.
- Text and UI pairs (`com.votepit.contrast`) are checked in both themes (`build-tokens.test.mjs`).
- Vote solids use light fills with ink text in the dark theme (white text on a 3:1 fill cannot reach 4.5:1 there).
- `tokensDark` in `tokens.generated.ts` lists the dark values that differ from light, for TS code that
  computes contrast (board accent, plates).

### Theme subtrees and fixed colours

- A `[data-theme]` element (`<html>`, the branding preview frame) re-declares every colour alias from `tokens.css`
  (`--color-vp-*`, `--vp-ink`, `--vp-on-ink`, `--vp-bg`), so light-in-dark and dark-in-light nest. The token test
  "subtree block mirrors the alias set" keeps that block equal to the `@theme` set.
- Fixed colours that stay literal in both schemes (each one carries a comment at its use):
  Votepit logo marks and plates (`Header`, `LogoField`, branding wordmark plate), the forest brand bar and the
  white text on it, the QR code (black on white must scan), the printed poster (paper), the generated cover's
  fallback accent, board presets and the default accent (data a board owner picks), the contrast-maths grounds
  in `ds/color.ts`, decorative confetti whites, the print rule.

## Escape hatches

`lint-spacing` accepts a line that carries `lint-allow: <reason>` in a trailing comment. Use it only for
optical corrections that have no token. Reviewers check the reason.

## Known gaps (not yet on tokens)

- Most app page shells use named theme tokens now. A few page-level values remain, for example `pl-[26px]` on the
  sign-up account page. Print sizes in mm and pt stay literal on purpose.
- The DS `TopBar` is used on app pages that have a top bar. The auth pages use a split panel without a top bar by
  design. Topbar-to-content spacing is checked per page during the app's visual review.

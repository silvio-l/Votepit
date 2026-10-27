import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { test } from "node:test";
import { checkBreakpoints, collect, contrastRatio, cssValue, DARK_KEY, OUTPUTS, render, resolve, resolveTheme, SOURCE, TokenError } from "./build-tokens.mjs";
import { lintSpacing } from "./lint-spacing.mjs";

const color = (v) => ({ $type: "color", $value: v });
const px = (n) => ({ $type: "dimension", $value: { value: n, unit: "px" } });

/** Smallest valid document; tests override single branches of it. */
function doc(overrides = {}) {
	return {
		primitive: { color: { ink: color("#151918"), white: color("#FFFFFF") }, space: { 4: px(4) } },
		semantic: { text: { primary: color("{primitive.color.ink}") }, surface: { card: color("{primitive.color.white}") } },
		component: { card: { text: color("{semantic.text.primary}"), gap: { $type: "dimension", $value: "{semantic.gap}" } } },
		...overrides,
	};
}
const withSemantic = (extra) => doc({ semantic: { ...doc().semantic, gap: px(8), ...extra } });
const fails = (d, pattern) => assert.throws(() => render(d), (e) => e instanceof TokenError && pattern.test(e.message));

test("the real token source validates and the committed artefacts are current", () => {
	const { files } = render(JSON.parse(readFileSync(SOURCE, "utf8")));
	for (const [key, path] of Object.entries(OUTPUTS)) assert.equal(readFileSync(path, "utf8"), files[key], `${key} is stale`);
});

test("generation is deterministic", () => {
	const source = JSON.parse(readFileSync(SOURCE, "utf8"));
	assert.deepEqual(render(source).files, render(structuredClone(source)).files);
});

test("a minimal document renders layered custom properties with var() chains", () => {
	const { files } = render(withSemantic());
	assert.match(files.css, /--vpds-p-color-ink: #151918;/);
	assert.match(files.css, /--vpds-text-primary: var\(--vpds-p-color-ink\);/);
	assert.match(files.css, /--vpds-c-card-text: var\(--vpds-text-primary\);/);
	assert.match(files.theme, /--color-ds-text-primary: var\(--vpds-text-primary\);/);
	assert.match(files.ts, /"component.card.text": \{ cssVar: "--vpds-c-card-text", type: "color", value: "#151918" \}/);
});

test("rejects unresolved aliases", () => {
	fails(doc({ semantic: { text: { primary: color("{primitive.color.missing}") } } }), /unresolved alias/);
});

test("rejects alias cycles", () => {
	fails(
		withSemantic({ a: { $type: "dimension", $value: "{semantic.b}" }, b: { $type: "dimension", $value: "{semantic.a}" } }),
		/alias cycle/,
	);
});

test("rejects literal colours outside the primitive layer", () => {
	fails(withSemantic({ text: { primary: color("#000000") } }), /must alias a primitive/);
});

test("rejects aliases into a higher layer and components skipping semantics", () => {
	fails(withSemantic({ up: color("{component.card.text}") }), /may not alias the component layer/);
	fails(doc({ component: { card: { text: color("{primitive.color.ink}") } } }), /alias semantic roles, not primitives/);
	fails(doc({ primitive: { ...doc().primitive, copy: color("{primitive.color.ink}") } }), /primitives must be literal/);
});

test("rejects alias type mismatches", () => {
	fails(withSemantic({ gap: { $type: "dimension", $value: "{primitive.color.ink}" } }), /type dimension aliases/);
});

test("rejects unsupported units, types and malformed values", () => {
	fails(withSemantic({ gap: { $type: "dimension", $value: { value: 8, unit: "vw" } } }), /unit "vw" not allowed/);
	fails(withSemantic({ gap: { $type: "dimension", $value: 8 } }), /must be \{value, unit\}/);
	fails(withSemantic({ odd: { $type: "gradient", $value: "x" } }), /unsupported \$type/);
	fails(doc({ primitive: { ...doc().primitive, bad: color("#fff") } }), /#RRGGBB/);
});

test("rejects CSS name collisions", () => {
	fails(
		withSemantic({ "a-b": { $type: "number", $value: 1 }, a: { b: { $type: "number", $value: 2 } } }),
		/CSS name collision --vpds-a-b/,
	);
	fails(withSemantic({ p: { x: { $type: "number", $value: 1 } } }), /ambiguous with a layer prefix/);
});

test("fails contrast pairs below their minimum", () => {
	const d = withSemantic({ faint: color("{primitive.color.white}") });
	d.$extensions = { "com.votepit.contrast": [{ fg: "semantic.faint", bg: "semantic.surface.card", min: 4.5, use: "test" }] };
	fails(d, /needs 4.5:1/);
});

test("contrast ratio matches the WCAG formula", () => {
	assert.equal(contrastRatio("#000000", "#FFFFFF").toFixed(2), "21.00");
	assert.equal(contrastRatio("#767676", "#FFFFFF").toFixed(2), "4.54");
});

test("serialises fluid dimensions as clamp() that hits both ends", () => {
	const value = cssValue("fluidDimension", {
		min: { value: 16, unit: "px" },
		max: { value: 64, unit: "px" },
		minViewport: { value: 375, unit: "px" },
		maxViewport: { value: 2560, unit: "px" },
	});
	const [, intercept, slope] = value.match(/clamp\(16px, ([\d.-]+)px \+ ([\d.]+)vw, 64px\)/).map(Number);
	assert.ok(Math.abs(intercept + (slope * 375) / 100 - 16) < 0.01);
	assert.ok(Math.abs(intercept + (slope * 2560) / 100 - 64) < 0.01);
});

test("settings field widths map to Tailwind max-w utilities (max-w-ds-field, max-w-ds-field-long)", () => {
	const { files } = render(JSON.parse(readFileSync(SOURCE, "utf8")));
	const theme = Object.values(files).find((f) => f.includes("@theme inline"));
	assert.match(theme, /--container-ds-field: var\(--vpds-layout-field-width\);/);
	assert.match(theme, /--container-ds-field-long: var\(--vpds-layout-field-width-long\);/);
});

test("media queries in DS stylesheets must use breakpoint tokens", () => {
	const d = withSemantic();
	d.primitive.breakpoint = { lg: px(1024) };
	const tokens = collect(d);
	const resolved = resolve(tokens);
	const dir = mkdtempSync(join(tmpdir(), "vpds-"));
	const ok = join(dir, "ok.css");
	const bad = join(dir, "bad.css");
	writeFileSync(ok, "@media (min-width: 1024px) {}\n@media (max-width: 1023.98px) {}\n");
	writeFileSync(bad, "@media (min-width: 900px) {}\n");
	assert.doesNotThrow(() => checkBreakpoints(tokens, resolved, [ok]));
	assert.throws(() => checkBreakpoints(tokens, resolved, [bad]), /900px, not a breakpoint token/);
});

test("spacing lint: raw px/rem spacing, radius and gap values are rejected", () => {
	const css = [".a {", "  padding: 4px 8px;", "  margin: 0;", "  border: 1px solid red;", "  gap: var(--vpds-space-sm);", "  border-radius: 2px;", "  row-gap: 1.5rem;", "}"].join("\n");
	assert.deepEqual(
		lintSpacing(css).map((v) => [v.line, v.property]),
		[[2, "padding"], [6, "border-radius"], [7, "row-gap"]],
	);
});

test("spacing lint: lint-allow comments and hairlines pass", () => {
	assert.deepEqual(lintSpacing("  padding: 3px; /* lint-allow: optical nudge */\n  border-width: 1px;"), []);
});

test("spacing lint: the committed DS stylesheets have no raw spacing", () => {
	const dsCss = readFileSync(new URL("../src/ds/components.css", import.meta.url), "utf8");
	assert.deepEqual(lintSpacing(dsCss), []);
});

test("motion tokens: one scale, reduced motion zeroes every duration, nothing above the max", () => {
	const source = JSON.parse(readFileSync(SOURCE, "utf8"));
	const { files, resolved } = render(source);
	const motion = [...resolved.keys()].filter((name) => name.startsWith("semantic.motion.") && name !== "semantic.motion.easing");
	const reduced = files.css.match(/@media \(prefers-reduced-motion: reduce\) \{[\s\S]*\}/)?.[0] ?? "";
	for (const name of motion) {
		const token = source.semantic.motion[name.split(".").pop()];
		if (!token.$extensions) continue;
		assert.match(reduced, new RegExp(`--vpds-motion-${name.split(".").pop()}: var\\(--vpds-p-duration-instant\\)`), name);
	}
	const max = resolved.get("primitive.duration.emphasis").value;
	// The celebration overlay is the one documented exception above the max (DESIGN.md, Motion).
	const celebration = ["semantic.motion.celebration", "semantic.motion.celebration-sparkle"];
	for (const name of motion) {
		if (name === "semantic.motion.easing" || celebration.includes(name)) continue;
		assert.ok(resolved.get(name).value <= max, `${name} exceeds the motion max`);
	}
	assert.deepEqual(
		motion.filter((name) => resolved.get(name).value > max),
		celebration,
		"only the celebration tokens may exceed the motion max",
	);
	assert.match(files.theme, /--duration-ds-fast: var\(--vpds-motion-fast\);/);
	assert.match(files.theme, /--default-transition-duration: var\(--vpds-motion-fast\);/);
	assert.match(files.theme, /--default-transition-timing-function: var\(--vpds-motion-easing\);/);
});

test("dark theme: every semantic colour and shadow role carries a dark value", () => {
	const source = JSON.parse(readFileSync(SOURCE, "utf8"));
	const { tokens } = render(source);
	const roles = [...tokens.values()].filter((t) => t.layer === "semantic" && (t.type === "color" || t.type === "shadow"));
	assert.ok(roles.length > 60, "the role list must not be empty");
	const missing = roles.filter((t) => t.extensions[DARK_KEY] === undefined).map((t) => t.name);
	assert.deepEqual(missing, [], "semantic roles without a dark value");
});

test("dark theme: resolves every token without unresolved aliases", () => {
	const { tokens } = render(JSON.parse(readFileSync(SOURCE, "utf8")));
	const dark = resolveTheme(tokens, "dark");
	assert.equal(dark.size, tokens.size);
	for (const [name, value] of dark) {
		assert.ok(value !== undefined && !String(value).includes("{"), `${name} resolves to ${value}`);
	}
});

test("dark theme: every contrast pair holds in light and in dark", () => {
	const { contrast } = render(JSON.parse(readFileSync(SOURCE, "utf8")));
	assert.deepEqual([...new Set(contrast.map((r) => r.theme))].sort(), ["dark", "light"]);
	for (const r of contrast) assert.ok(r.ratio >= r.min, `${r.theme} ${r.fg} on ${r.bg}: ${r.ratio.toFixed(2)} < ${r.min}`);
});

test("dark CSS: explicit choice and system preference, guarded so an explicit light wins", () => {
	const { files } = render(JSON.parse(readFileSync(SOURCE, "utf8")));
	assert.match(files.css, /\[data-theme="dark"\] \{\n  color-scheme: dark;/);
	// Light scheme also on [data-theme="light"], so a subtree can opt out of a dark root (board preview).
	assert.match(files.css, /:root,\n\[data-theme="light"\] \{\n  color-scheme: light;/);
	assert.match(files.css, /@media \(prefers-color-scheme: dark\) \{\n  :root:not\(\[data-theme="light"\]\) \{/);
	assert.match(files.css, /\[data-theme="dark"\] \{[\s\S]*--vpds-text-primary: var\(--vpds-p-color-night-paper\);/);
});

test("dark theme: a dark colour must alias a primitive colour, a dark shadow must be a literal", () => {
	const withDark = (role, value) => {
		const d = withSemantic({ ink: { ...color("{primitive.color.ink}"), $extensions: { [DARK_KEY]: value } } });
		return d;
	};
	fails(withDark("ink", "#000000"), /dark colour must alias a primitive colour/);
	fails(withDark("ink", "{semantic.surface.card}"), /dark colour must alias a primitive colour/);
	fails(withSemantic({ ink: { ...color("{primitive.color.ink}"), $extensions: { [DARK_KEY]: "{primitive.color.missing}" } } }), /dark colour must alias a primitive colour/);
	fails(withSemantic({ gap: { $type: "dimension", $value: { value: 8, unit: "px" }, $extensions: { [DARK_KEY]: { value: 4, unit: "px" } } } }), /only colour and shadow roles/);
});

// Every colour alias in tokens.css @theme must be re-declared on the [data-theme] subtree block with the same
// value. Without it a nested light/dark subtree (branding preview frame) keeps the :root value of the alias.
test("subtree block mirrors the alias set", () => {
	const css = readFileSync(new URL("../src/tokens.css", import.meta.url), "utf8");
	const block = (header) => {
		const start = css.indexOf(header);
		assert.notEqual(start, -1, `${header} not found`);
		const end = css.indexOf("\n}", start);
		return css.slice(start, end);
	};
	const declarations = (text) => new Map([...text.matchAll(/^\s*(--(?:color-vp|vp)-[a-z0-9-]+):\s*(.+?);/gm)].map((m) => [m[1], m[2]]));
	const theme = declarations(block("@theme {"));
	const subtree = declarations(block("[data-theme] {"));
	const roles = [...theme].filter(([name, value]) => value.includes("var(") && name !== "--color-vp-cloud-ink");
	assert.ok(roles.length >= 40, `expected the semantic alias set, found ${roles.length}`);
	for (const hook of ["--vp-ink", "--vp-on-ink", "--vp-bg"]) assert.ok(subtree.has(hook), `${hook} missing from subtree`);
	for (const [name, value] of roles) {
		assert.ok(subtree.has(name), `${name} missing from the [data-theme] subtree block`);
		assert.equal(subtree.get(name), value, `${name} differs in the [data-theme] subtree block`);
	}
	// Literal colours in the alias layer would not follow the scheme; only the fixed edition mark may stay literal.
	const literals = [...theme].filter(([name, value]) => /#[0-9a-f]{3,8}\b|rgba?\(/i.test(value) && name !== "--color-vp-cloud-ink");
	assert.deepEqual(literals, [], "theme aliases must derive from semantic roles, not literal colours");
});

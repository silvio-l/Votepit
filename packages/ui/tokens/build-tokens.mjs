#!/usr/bin/env node
// Votepit design-token generator (no dependencies, deterministic).
//
//   node core/packages/ui/tokens/build-tokens.mjs           # validate + write artefacts
//   node core/packages/ui/tokens/build-tokens.mjs --check   # validate + fail if artefacts are stale
//
// Source: tokens.json (primitive -> semantic -> component). Artefacts (never edit by hand):
//   src/ds/tokens.generated.css  CSS custom properties (--vpds-p-*, --vpds-*, --vpds-c-*)
//   src/ds/theme.generated.css   Tailwind v4 `@theme inline` mapping onto those properties
//   src/ds/tokens.generated.ts   typed, resolved values for TS consumers (vote meter, tests)

import { readFileSync, writeFileSync, existsSync, mkdirSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const PKG = join(HERE, "..");
export const SOURCE = join(HERE, "tokens.json");
export const OUTPUTS = {
	css: join(PKG, "src/ds/tokens.generated.css"),
	theme: join(PKG, "src/ds/theme.generated.css"),
	ts: join(PKG, "src/ds/tokens.generated.ts"),
};
// Stylesheets whose media queries must only use token breakpoints.
export const BREAKPOINT_CONSUMERS = [join(PKG, "src/ds/components.css")];

const LAYERS = ["primitive", "semantic", "component"];
const PREFIX = { primitive: "--vpds-p-", semantic: "--vpds-", component: "--vpds-c-" };
const TYPES = new Set([
	"color",
	"dimension",
	"fluidDimension",
	"duration",
	"cubicBezier",
	"fontFamily",
	"fontWeight",
	"number",
	"shadow",
]);
const UNITS = { dimension: new Set(["px", "rem", "em", "ch"]), duration: new Set(["ms"]) };
// A dimension alias may point at a fluid dimension (both resolve to a CSS length).
const COMPATIBLE = { dimension: new Set(["dimension", "fluidDimension"]) };
const ALIAS = /^\{([^{}]+)\}$/;
const HEX = /^#(?:[0-9A-F]{6}|[0-9A-F]{8})$/;
const ROOT_PX = 16;
/** Extension holding a semantic role's dark value (alias to a primitive colour, or a literal shadow). */
export const DARK_KEY = "com.votepit.dark";

export class TokenError extends Error {}

/** Flattens the layered tree into an ordered list of tokens; enforces structure and types. */
export function collect(doc) {
	const errors = [];
	const tokens = new Map();
	for (const key of Object.keys(doc)) {
		if (!key.startsWith("$") && !LAYERS.includes(key)) errors.push(`unknown top-level group "${key}"`);
	}
	const walk = (node, path, layer, inheritedType) => {
		if (node === null || typeof node !== "object" || Array.isArray(node)) {
			errors.push(`${path.join(".")}: expected a group or token object`);
			return;
		}
		const type = node.$type ?? inheritedType;
		if ("$value" in node) {
			const name = path.join(".");
			if (!type) errors.push(`${name}: missing $type`);
			else if (!TYPES.has(type)) errors.push(`${name}: unsupported $type "${type}"`);
			for (const segment of path.slice(1)) {
				if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(segment)) errors.push(`${name}: segment "${segment}" is not kebab-case`);
			}
			tokens.set(name, { name, layer, type, raw: node.$value, extensions: node.$extensions ?? {}, description: node.$description });
			return;
		}
		for (const [key, child] of Object.entries(node)) {
			if (key.startsWith("$")) continue;
			walk(child, [...path, key], layer, type);
		}
	};
	for (const layer of LAYERS) {
		if (!doc[layer]) errors.push(`missing layer "${layer}"`);
		else walk(doc[layer], [layer], layer, undefined);
	}
	if (errors.length) throw new TokenError(errors.join("\n"));
	return tokens;
}

const aliasTarget = (raw) => (typeof raw === "string" ? raw.match(ALIAS)?.[1] : undefined);

/** Validates literals, alias layering, alias types and cycles; returns tokens with resolved values. */
export function resolve(tokens) {
	const errors = [];
	for (const token of tokens.values()) {
		const target = aliasTarget(token.raw);
		if (target) {
			const ref = tokens.get(target);
			if (!ref) {
				errors.push(`${token.name}: unresolved alias {${target}}`);
				continue;
			}
			const from = LAYERS.indexOf(token.layer);
			const to = LAYERS.indexOf(ref.layer);
			if (token.layer === "primitive") errors.push(`${token.name}: primitives must be literal values`);
			else if (to > from) errors.push(`${token.name}: ${token.layer} may not alias the ${ref.layer} layer`);
			else if (token.layer === "component" && ref.layer === "primitive") {
				errors.push(`${token.name}: components alias semantic roles, not primitives`);
			}
			if (ref.type !== token.type && !COMPATIBLE[token.type]?.has(ref.type)) {
				errors.push(`${token.name}: type ${token.type} aliases ${target} of type ${ref.type}`);
			}
		} else {
			if (token.type === "color" && token.layer !== "primitive") {
				errors.push(`${token.name}: ${token.layer} colours must alias a primitive (no literal colours)`);
			}
			errors.push(...validateLiteral(token));
		}
		const reduced = token.extensions["com.votepit.reducedMotion"];
		if (reduced !== undefined) {
			const ref = tokens.get(aliasTarget(reduced) ?? "");
			if (!ref || ref.type !== "duration") errors.push(`${token.name}: reducedMotion must alias a duration token`);
		}
	}
	for (const token of tokens.values()) {
		const dark = token.extensions[DARK_KEY];
		if (dark === undefined) continue;
		if (token.layer !== "semantic") errors.push(`${token.name}: only semantic roles carry a dark value`);
		else if (token.type === "color") {
			const target = aliasTarget(dark);
			const ref = target ? tokens.get(target) : undefined;
			if (!ref || ref.layer !== "primitive" || ref.type !== "color") errors.push(`${token.name}: dark colour must alias a primitive colour`);
		} else if (token.type === "shadow") {
			errors.push(...validateLiteral({ name: `${token.name} (dark)`, type: "shadow", raw: dark }));
		} else errors.push(`${token.name}: only colour and shadow roles may carry a dark value`);
	}
	if (errors.length) throw new TokenError(errors.join("\n"));
	return resolveTheme(tokens, "light");
}

/**
 * Resolved value of every token for one theme ("light" or "dark"). In the dark theme a token
 * with a DARK_KEY value takes it; every other token keeps its light definition, and aliases
 * follow their target in the same theme (so components track the dark semantic roles).
 */
export function resolveTheme(tokens, theme) {
	const resolved = new Map();
	const resolving = new Set();
	const valueOf = (name, chain) => {
		if (resolved.has(name)) return resolved.get(name);
		if (resolving.has(name)) throw new TokenError(`alias cycle: ${[...chain, name].join(" -> ")}`);
		resolving.add(name);
		const token = tokens.get(name);
		const raw = theme === "dark" && token.extensions[DARK_KEY] !== undefined ? token.extensions[DARK_KEY] : token.raw;
		const target = aliasTarget(raw);
		const value = target ? valueOf(target, [...chain, name]) : raw;
		resolving.delete(name);
		resolved.set(name, value);
		return value;
	};
	for (const name of tokens.keys()) valueOf(name, []);
	return resolved;
}

function validateLiteral(token) {
	const { name, type, raw } = token;
	const errors = [];
	const isNumber = (v) => typeof v === "number" && Number.isFinite(v);
	const unitValue = (v, units, label) => {
		if (!v || typeof v !== "object" || !isNumber(v.value) || typeof v.unit !== "string") {
			errors.push(`${name}: ${label} must be {value, unit}`);
		} else if (!units.has(v.unit)) {
			errors.push(`${name}: ${label} unit "${v.unit}" not allowed (${[...units].join(", ")})`);
		}
	};
	switch (type) {
		case "color":
			if (typeof raw !== "string" || !HEX.test(raw)) errors.push(`${name}: colour must be #RRGGBB or #RRGGBBAA (uppercase)`);
			break;
		case "dimension":
			unitValue(raw, UNITS.dimension, "dimension");
			break;
		case "duration":
			unitValue(raw, UNITS.duration, "duration");
			break;
		case "fluidDimension":
			for (const key of ["min", "max", "minViewport", "maxViewport"]) unitValue(raw?.[key], UNITS.dimension, key);
			if (!errors.length) {
				if (raw.min.unit !== raw.max.unit) errors.push(`${name}: min/max units differ`);
				if (raw.minViewport.unit !== "px" || raw.maxViewport.unit !== "px") errors.push(`${name}: viewports must be px`);
				if (raw.min.value > raw.max.value) errors.push(`${name}: min exceeds max`);
				if (raw.minViewport.value >= raw.maxViewport.value) errors.push(`${name}: minViewport must be below maxViewport`);
			}
			break;
		case "cubicBezier":
			if (!Array.isArray(raw) || raw.length !== 4 || !raw.every(isNumber) || raw[0] < 0 || raw[0] > 1 || raw[2] < 0 || raw[2] > 1) {
				errors.push(`${name}: cubicBezier must be [x1, y1, x2, y2] with x in 0..1`);
			}
			break;
		case "fontFamily":
			if (!Array.isArray(raw) || !raw.length || !raw.every((f) => typeof f === "string" && f.length)) errors.push(`${name}: fontFamily must be a non-empty string list`);
			break;
		case "fontWeight":
			if (!isNumber(raw) || raw < 100 || raw > 900 || raw % 100 !== 0) errors.push(`${name}: fontWeight must be 100..900 in steps of 100`);
			break;
		case "number":
			if (!isNumber(raw)) errors.push(`${name}: number expected`);
			break;
		case "shadow":
			if (!Array.isArray(raw) || !raw.length) errors.push(`${name}: shadow must be a non-empty layer list`);
			else {
				for (const layer of raw) {
					if (typeof layer.color !== "string" || !HEX.test(layer.color)) errors.push(`${name}: shadow colour must be hex`);
					for (const key of ["offsetX", "offsetY", "blur", "spread"]) unitValue(layer[key], new Set(["px"]), `shadow ${key}`);
				}
			}
			break;
	}
	return errors;
}

// ---------- CSS serialisation ----------

const fmt = (n) => String(Number(n.toFixed(4)));
const dim = (v) => `${fmt(v.value)}${v.unit}`;

function fluid(v) {
	const toPx = (x) => (x.unit === "rem" ? x.value * ROOT_PX : x.value);
	const minPx = toPx(v.min);
	const maxPx = toPx(v.max);
	const slope = (maxPx - minPx) / (v.maxViewport.value - v.minViewport.value);
	const interceptPx = minPx - slope * v.minViewport.value;
	const intercept = v.min.unit === "rem" ? `${fmt(interceptPx / ROOT_PX)}rem` : `${fmt(interceptPx)}px`;
	return `clamp(${dim(v.min)}, ${intercept} + ${fmt(slope * 100)}vw, ${dim(v.max)})`;
}

export function cssValue(type, value) {
	switch (type) {
		case "dimension":
		case "duration":
			return dim(value);
		case "fluidDimension":
			return fluid(value);
		case "cubicBezier":
			return `cubic-bezier(${value.map(fmt).join(", ")})`;
		case "fontFamily":
			return value.map((f) => (/[\s"]/.test(f) ? `"${f}"` : f)).join(", ");
		case "shadow":
			return value.map((l) => `${dim(l.offsetX)} ${dim(l.offsetY)} ${dim(l.blur)} ${dim(l.spread)} ${l.color}`).join(", ");
		default:
			return String(value);
	}
}

export const cssVarName = (token) => PREFIX[token.layer] + token.name.split(".").slice(1).join("-");

/** Every token gets exactly one custom property; two paths mapping to one name is an error. */
export function cssNames(tokens) {
	const byVar = new Map();
	const errors = [];
	for (const token of tokens.values()) {
		const name = cssVarName(token);
		if (byVar.has(name)) errors.push(`CSS name collision ${name}: ${byVar.get(name)} and ${token.name}`);
		byVar.set(name, token.name);
	}
	// Semantic names have the shortest prefix, so guard them against shadowing the other layers.
	for (const name of byVar.keys()) {
		if (byVar.get(name).startsWith("semantic.") && (name.startsWith("--vpds-p-") || name.startsWith("--vpds-c-"))) {
			errors.push(`CSS name ${name} (${byVar.get(name)}) is ambiguous with a layer prefix`);
		}
	}
	if (errors.length) throw new TokenError(errors.join("\n"));
	return new Map([...tokens.values()].map((t) => [t.name, cssVarName(t)]));
}

// ---------- contrast ----------

function luminance(hex) {
	const channel = (i) => {
		const c = Number.parseInt(hex.slice(i, i + 2), 16) / 255;
		return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
	};
	return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

export function contrastRatio(fg, bg) {
	const [a, b] = [luminance(fg), luminance(bg)].sort((x, y) => y - x);
	return (a + 0.05) / (b + 0.05);
}

/** Every contrast pair must hold in both themes (`themes`: name -> resolved map). */
export function checkContrast(doc, tokens, themes) {
	const pairs = doc.$extensions?.["com.votepit.contrast"] ?? [];
	const errors = [];
	const results = [];
	for (const pair of pairs) {
		if (tokens.get(pair.fg)?.type !== "color" || tokens.get(pair.bg)?.type !== "color") {
			errors.push(`contrast pair ${pair.fg} / ${pair.bg}: both must be colour tokens`);
			continue;
		}
		for (const [theme, resolved] of Object.entries(themes)) {
			const fg = resolved.get(pair.fg);
			const bg = resolved.get(pair.bg);
			if (fg.length !== 7 || bg.length !== 7) {
				errors.push(`contrast pair ${pair.fg} / ${pair.bg} (${theme}): translucent colours cannot be checked`);
				continue;
			}
			const ratio = contrastRatio(fg, bg);
			results.push({ ...pair, theme, ratio });
			if (ratio < pair.min) errors.push(`contrast ${pair.fg} on ${pair.bg} (${theme}) = ${ratio.toFixed(2)}:1, needs ${pair.min}:1 (${pair.use})`);
		}
	}
	if (errors.length) throw new TokenError(errors.join("\n"));
	return results;
}

// ---------- breakpoints ----------

/** Media queries in DS stylesheets may only use breakpoint tokens (CSS cannot read var() there). */
export function checkBreakpoints(tokens, resolved, files) {
	const allowed = new Set();
	for (const token of tokens.values()) {
		if (token.name.startsWith("primitive.breakpoint.")) allowed.add(resolved.get(token.name).value);
	}
	const errors = [];
	for (const file of files) {
		if (!existsSync(file)) continue;
		const css = readFileSync(file, "utf8");
		for (const media of css.matchAll(/@media[^{]*/g)) {
			for (const m of media[0].matchAll(/(\d+(?:\.\d+)?)px/g)) {
				// max-width queries use (breakpoint - 0.02px) so they never overlap a min-width query.
				const px = Number(m[1]);
				const base = allowed.has(px) ? px : Math.round((px + 0.02) * 100) / 100;
				if (!allowed.has(base)) errors.push(`${relative(PKG, file)}: media query uses ${px}px, not a breakpoint token`);
			}
		}
	}
	if (errors.length) throw new TokenError(errors.join("\n"));
}

// ---------- artefacts ----------

const HEADER = "Generated by core/packages/ui/tokens/build-tokens.mjs from tokens.json — do not edit.";

export function render(doc) {
	const tokens = collect(doc);
	const resolved = resolve(tokens);
	const darkResolved = resolveTheme(tokens, "dark");
	const names = cssNames(tokens);
	const contrast = checkContrast(doc, tokens, { light: resolved, dark: darkResolved });

	const decl = (token) => {
		const target = aliasTarget(token.raw);
		const value = target ? `var(${names.get(target)})` : cssValue(token.type, token.raw);
		return `  ${names.get(token.name)}: ${value};`;
	};
	const blocks = LAYERS.map((layer) => {
		const lines = [...tokens.values()].filter((t) => t.layer === layer).map(decl);
		return `  /* ${layer} */\n${lines.join("\n")}`;
	});
	const reduced = [...tokens.values()]
		.filter((t) => t.extensions["com.votepit.reducedMotion"])
		.map((t) => `    ${names.get(t.name)}: var(${names.get(aliasTarget(t.extensions["com.votepit.reducedMotion"]))});`);
	// Dark theme: the semantic roles with a dark value, on [data-theme="dark"] (explicit choice) and,
	// when no explicit choice is made, on the system preference (guarded by :root:not([data-theme="light"])).
	const darkLines = [...tokens.values()]
		.filter((t) => t.extensions[DARK_KEY] !== undefined)
		.map((t) => {
			const dark = t.extensions[DARK_KEY];
			const target = aliasTarget(dark);
			return `  ${names.get(t.name)}: ${target ? `var(${names.get(target)})` : cssValue(t.type, dark)};`;
		});
	const darkBody = ["  color-scheme: dark;", ...darkLines];
	const darkCss = `\n[data-theme="dark"] {\n${darkBody.join("\n")}\n}\n\n@media (prefers-color-scheme: dark) {\n  :root:not([data-theme="light"]) {\n${darkBody.map((l) => `  ${l}`).join("\n")}\n  }\n}\n`;
	const css = `/* ${HEADER} */\n:root,\n[data-theme="light"] {\n  color-scheme: light;\n\n${blocks.join("\n\n")}\n}\n${darkCss}${
		reduced.length ? `\n@media (prefers-reduced-motion: reduce) {\n  :root {\n${reduced.join("\n")}\n  }\n}\n` : ""
	}`;

	// Tailwind v4 namespaces: utilities such as bg-ds-canvas, text-ds-secondary, rounded-ds-card.
	const theme = [];
	const map = (twName, tokenName) => theme.push(`  ${twName}: var(${names.get(tokenName)});`);
	for (const token of tokens.values()) {
		if (token.layer !== "semantic") continue;
		const rest = token.name.split(".").slice(1);
		const [group] = rest;
		const tail = rest.slice(1).join("-");
		if (token.type === "color") map(`--color-ds-${rest.join("-")}`, token.name);
		else if (group === "radius") map(`--radius-ds-${tail}`, token.name);
		else if (group === "shadow") map(`--shadow-ds-${tail}`, token.name);
		else if (group === "font") map(`--font-ds-${tail}`, token.name);
		else if (group === "space") map(`--spacing-ds-${tail}`, token.name);
		else if (group === "motion" && token.type === "cubicBezier") map(`--ease-ds-${tail}`, token.name);
		else if (group === "motion" && token.type === "duration") map(`--duration-ds-${tail}`, token.name);
		else if (group === "type" && rest[2] === "size") map(`--text-ds-${rest[1]}`, token.name);
		else if (group === "layout" && tail === "field-width") map("--container-ds-field", token.name);
		else if (group === "layout" && tail === "field-width-long") map("--container-ds-field-long", token.name);
		else if (group === "layout" && tail.startsWith("breakpoint-")) {
			// Tailwind needs literal breakpoints for its variants; they mirror the token.
			theme.push(`  --breakpoint-ds-${tail.slice("breakpoint-".length)}: ${cssValue("dimension", resolved.get(token.name))};`);
		}
	}
	// Tailwind defaults for bare `transition` / `transition-colors` follow the motion tokens (no 150ms literal).
	theme.push(`  --default-transition-duration: var(${names.get("semantic.motion.fast")});`);
	theme.push(`  --default-transition-timing-function: var(${names.get("semantic.motion.easing")});`);
	const themeCss = `/* ${HEADER} */\n/* Requires tokens.generated.css (the variables) to be loaded as well. */\n@theme inline {\n${theme.join("\n")}\n}\n`;

	const entries = [...tokens.values()].map((t) => {
		const value = resolved.get(t.name);
		return `  ${JSON.stringify(t.name)}: { cssVar: ${JSON.stringify(names.get(t.name))}, type: ${JSON.stringify(t.type)}, value: ${JSON.stringify(cssValue(t.type, value))} },`;
	});
	// Resolved values of the dark theme that differ from light (for TS consumers, e.g. accent contrast).
	const darkEntries = [...tokens.values()]
		.filter((t) => darkResolved.get(t.name) !== resolved.get(t.name))
		.map((t) => `  ${JSON.stringify(t.name)}: ${JSON.stringify(cssValue(t.type, darkResolved.get(t.name)))},`);
	const ts = `// ${HEADER}\n\nexport const tokens = {\n${entries.join("\n")}\n} as const;\n\nexport type TokenName = keyof typeof tokens;\n\n/** \`var(--vpds-…)\` reference for a token, for inline styles that must follow the token. */\nexport const tokenVar = (name: TokenName): string => \`var(\${tokens[name].cssVar})\`;\n\n/** Dark-theme values, only for tokens whose value differs from light (ink on forest etc.). */\nexport const tokensDark: Partial<Record<TokenName, string>> = {\n${darkEntries.join("\n")}\n};\n`;

	return { tokens, resolved, contrast, files: { css, theme: themeCss, ts } };
}

function main(argv) {
	const check = argv.includes("--check");
	const doc = JSON.parse(readFileSync(SOURCE, "utf8"));
	const { tokens, resolved, contrast, files } = render(doc);
	checkBreakpoints(tokens, resolved, BREAKPOINT_CONSUMERS);
	const stale = [];
	for (const [key, path] of Object.entries(OUTPUTS)) {
		const current = existsSync(path) ? readFileSync(path, "utf8") : null;
		if (current === files[key]) continue;
		if (check) stale.push(relative(PKG, path));
		else {
			mkdirSync(dirname(path), { recursive: true });
			writeFileSync(path, files[key]);
		}
	}
	const weakest = [...contrast].sort((a, b) => a.ratio - a.min - (b.ratio - b.min))[0];
	console.log(
		`tokens: ${tokens.size} ok · contrast pairs: ${contrast.length} ok (tightest ${weakest ? `${weakest.ratio.toFixed(2)}:1 for ${weakest.use}` : "n/a"})`,
	);
	if (stale.length) {
		console.error(`stale generated files (run build-tokens.mjs): ${stale.join(", ")}`);
		process.exit(1);
	}
	console.log(check ? "generated files are up to date" : "generated files written");
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
	try {
		main(process.argv.slice(2));
	} catch (error) {
		if (error instanceof TokenError) {
			console.error(`token validation failed:\n${error.message}`);
			process.exit(1);
		}
		throw error;
	}
}

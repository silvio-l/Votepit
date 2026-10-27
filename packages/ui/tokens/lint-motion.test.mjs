import assert from "node:assert/strict";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { test } from "node:test";
import { fileURLToPath } from "node:url";
import { lintMotion } from "./lint-motion.mjs";

const PKG = fileURLToPath(new URL("..", import.meta.url));
const APP_SRC = join(PKG, "../../app/src");

test("motion lint: raw times, easing keywords, bezier curves and loops are reported", () => {
	const css = [
		".a { transition: color 150ms ease; }",
		".b {",
		"  transition:",
		"    color var(--vpds-motion-fast) var(--vpds-motion-easing),",
		"    border-color 120ms linear;",
		"}",
		".c { animation: spin 2s infinite; }",
		".d { transition: transform cubic-bezier(0.2, 0, 0, 1) var(--vpds-motion-fast); }",
	].join("\n");
	assert.deepEqual(
		lintMotion(css).map((v) => [v.line, v.reason]),
		[
			[1, "raw time 150ms"],
			[1, "raw easing (use var(--vpds-motion-easing))"],
			[3, "raw time 120ms"],
			[3, "raw easing (use var(--vpds-motion-easing))"],
			[7, "raw time 2s"],
			[7, "infinite animation (nothing loops)"],
			[8, "raw easing (use var(--vpds-motion-easing))"],
		],
	);
});

test("motion lint: token references, zero times and lint-allow lines pass", () => {
	const css = [
		".ok { transition: color var(--vpds-motion-fast) var(--vpds-motion-easing); animation-delay: 0; }",
		".spin { animation: ds-spin var(--vpds-motion-emphasis) steps(8) infinite; /* lint-allow: loading indicator */ }",
		".reduce { animation-duration: 0s !important; }",
	].join("\n");
	assert.deepEqual(lintMotion(css), []);
});

test("motion lint: celebration tokens are the only exception, and only on their own overlay animation", () => {
	const ok = [
		"--animate-vp-confetti-fall: vp-confetti-fall var(--vpds-motion-celebration) var(--vpds-motion-easing) both;",
		"--animate-vp-sparkle-pop: vp-sparkle-pop var(--vpds-motion-celebration-sparkle) var(--vpds-motion-easing) both;",
	];
	assert.deepEqual(lintMotion(ok.join("\n")), []);
	const bad = [
		"  --animate-vp-confetti-fall: vp-confetti-fall var(--vpds-motion-celebration-sparkle) both;",
		"  --animate-vp-sparkle-pop: vp-sparkle-pop var(--vpds-motion-celebration) both;",
		"  --animate-vp-rise: vp-rise var(--vpds-motion-celebration) var(--vpds-motion-easing) both;",
		".banner { transition: color var(--vpds-motion-celebration) var(--vpds-motion-easing); }",
	].join("\n");
	assert.deepEqual(
		lintMotion(bad).map((v) => [v.line, v.reason]),
		[
			[1, "celebration token outside the Celebration overlay"],
			[1, "celebration overlay must use --vpds-motion-celebration"],
			[2, "celebration token outside the Celebration overlay"],
			[2, "celebration overlay must use --vpds-motion-celebration-sparkle"],
			[3, "celebration token outside the Celebration overlay"],
			[4, "celebration token outside the Celebration overlay"],
		],
	);
	assert.deepEqual(
		lintMotion("  --animate-vp-confetti-fall: vp-confetti-fall var(--vpds-motion-emphasis) both;").map((v) => v.reason),
		["celebration overlay must use --vpds-motion-celebration"],
	);
});

function* files(dir) {
	for (const name of readdirSync(dir)) {
		if (name === "node_modules") continue;
		const path = join(dir, name);
		if (statSync(path).isDirectory()) yield* files(path);
		else yield path;
	}
}

test("the committed DS and app stylesheets use motion tokens only", () => {
	for (const file of ["src/ds/components.css", "src/ds/mobile.css", "src/tokens.css"]) {
		assert.deepEqual(lintMotion(readFileSync(join(PKG, file), "utf8")), [], file);
	}
});

// Tailwind duration literals bypass the motion tokens. Tabs.tsx and Toast.tsx are owned by an
// open step (G-03 wave 3) and get their swap there; remove them from this list when they land.
const PENDING_OWNER = new Set();

test("TSX uses motion token classes, not Tailwind duration literals", () => {
	const roots = [APP_SRC, join(PKG, "src")];
	const offenders = [];
	for (const root of roots) {
		for (const path of files(root)) {
			if (!path.endsWith(".tsx") || /\.test\.|__tests__/.test(path)) continue;
			const rel = relative(join(PKG, ".."), path).split("\\").join("/");
			const rel2 = relative(join(PKG, "..", ".."), path).split("\\").join("/");
			if (PENDING_OWNER.has(rel) || PENDING_OWNER.has(rel2.replace(/^core\//, ""))) continue;
			const text = readFileSync(path, "utf8");
			if (/(?<![\w-])duration-(\d+|\[)/.test(text)) offenders.push(rel2);
		}
	}
	assert.deepEqual(offenders, []);
});

import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { lintTextHeight } from "./lint-text-height.mjs";

test("text-height lint: fixed height on wrapping text is rejected", () => {
	const css = [".nav {", "  height: 2.25rem;", "  font-size: 1rem;", "}"].join("\n");
	assert.deepEqual(lintTextHeight(css).map((v) => [v.line, v.selector]), [[1, ".nav"]]);
});

test("text-height lint: min-height, nowrap, square glyph boxes, comments and lint-allow pass", () => {
	const css = [
		".a { min-height: 2.25rem; font-size: 1rem; }",
		".b { height: 1.25rem; font-size: 1rem; white-space: nowrap; }",
		".c { width: 2rem; height: 2rem; font-size: 1rem; }",
		".d { /* height: 2rem; */ font-size: 1rem; }",
		".e { height: 2rem; font-size: 1rem; /* lint-allow: single glyph */ }",
		".f { height: 100%; font-size: 1rem; }",
	].join("\n");
	assert.deepEqual(lintTextHeight(css), []);
});

test("text-height lint: the committed DS stylesheets have no fixed-height wrapping text", () => {
	for (const file of ["../src/tokens.css", "../src/ds/components.css", "../src/ds/mobile.css"]) {
		assert.deepEqual(lintTextHeight(readFileSync(new URL(file, import.meta.url), "utf8")), [], file);
	}
});

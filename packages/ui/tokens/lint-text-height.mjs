// Text-height lint for DS stylesheets: a rule that sets text (font-size) must not
// pin a fixed `height` unless its text can never wrap (`white-space: nowrap`).
// A wrapping label in a fixed-height box spills over its own top and bottom
// spacing (owner finding 2026-10-25: two-line sidebar item). Use `min-height`
// plus block padding instead. Exempt: square glyph boxes (width equals height,
// e.g. avatar initials, step markers) and `lint-allow: <reason>` in the rule.

const HEIGHT = /(?:^|[;{\s])height\s*:\s*([^;{}]+)/;
const FONT_SIZE = /(?:^|[;{\s])font-size\s*:/;
const NOWRAP = /white-space\s*:\s*nowrap/;
const WIDTH = /(?:^|[;{\s])width\s*:\s*([^;{}]+)/;
const FREE = /^(auto|100%|fit-content|max-content|min-content|inherit|initial|unset)$/;

/** @returns {{line: number, selector: string, value: string}[]} */
export function lintTextHeight(css) {
	const violations = [];
	// Innermost blocks only: selector text directly before a brace pair without nested braces.
	// Comments are blanked (newlines kept for line numbers); a lint-allow comment leaves its marker.
	const plain = css.replace(/\/\*[\s\S]*?\*\//g, (c) => (c.includes("lint-allow:") ? " lint-allow: " : "") + c.replace(/[^\n]/g, ""));
	for (const m of plain.matchAll(/([^{}]*)\{([^{}]*)\}/g)) {
		const body = m[2];
		const height = body.match(HEIGHT);
		if (!height || !FONT_SIZE.test(body)) continue;
		const value = height[1].trim();
		const width = body.match(WIDTH)?.[1].trim();
		if (FREE.test(value) || width === value || NOWRAP.test(body) || body.includes("lint-allow:")) continue;
		const selector = m[1].trim().split("\n").pop().trim();
		const line = plain.slice(0, m.index + m[0].indexOf("{")).split("\n").length;
		violations.push({ line, selector, value });
	}
	return violations;
}

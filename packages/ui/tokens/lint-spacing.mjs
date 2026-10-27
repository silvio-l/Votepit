// Spacing lint for DS stylesheets: raw px/rem values in spacing, radius and gap
// declarations must go through the space/radius tokens (var(--vpds-…)).
// Allowed without a token: 0, the 1px hairline (border widths only) and any
// line carrying `lint-allow: <reason>` in a trailing comment.

const SPACING_PROP = /^(padding(-[a-z]+)*|margin(-[a-z]+)*|gap|row-gap|column-gap|border(-[a-z]+)*-radius|border-radius)$/;
const RAW_LENGTH = /(?<![\w-])(-?\d*\.?\d+)(px|rem|em)\b/g;

/** @returns {{line: number, property: string, value: string}[]} */
export function lintSpacing(css) {
	const violations = [];
	css.split("\n").forEach((text, index) => {
		if (text.includes("lint-allow:")) return;
		const decl = text.match(/^\s*([a-z-]+)\s*:\s*([^;{}]+)[;}]?/);
		if (!decl) return;
		const [, property, value] = decl;
		if (!SPACING_PROP.test(property)) return;
		const raw = [...value.matchAll(RAW_LENGTH)].filter((m) => Number(m[1]) !== 0);
		if (raw.length) violations.push({ line: index + 1, property, value: value.trim() });
	});
	return violations;
}

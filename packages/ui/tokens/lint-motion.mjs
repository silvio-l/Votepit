// Motion lint for DS and app stylesheets: every transition/animation duration, delay
// and easing must come from the motion tokens (var(--vpds-motion-*)). Raw time
// literals, raw cubic-bezier() curves and easing keywords are rejected, and so are
// infinite animations (nothing auto-plays in a loop). Exceptions: a declaration
// that carries `lint-allow: <reason>` on any of its lines, and a literal 0.

const MOTION_PROP = /^(transition|animation|transition-duration|transition-delay|animation-duration|animation-delay|animation-timing-function|transition-timing-function|--animate-[a-z0-9-]+)$/;
const RAW_TIME = /(?<![\w.-])(\d*\.?\d+)(ms|s)\b/g;
const RAW_EASE = /cubic-bezier\(|(?<![\w-])(ease|ease-in|ease-out|ease-in-out|linear)(?![\w-])/;
const LOOP = /(?<![\w-])infinite(?![\w-])/;
// The celebration tokens are the one documented exception above the motion max (DESIGN.md, Motion).
// They may only drive the Celebration overlay keyframes, each on its own animation.
const CELEBRATION_REF = /--vpds-motion-celebration(?:-sparkle)?(?![\w-])/g;
const CELEBRATION_OWNER = {
	"--animate-vp-confetti-fall": "--vpds-motion-celebration",
	"--animate-vp-sparkle-pop": "--vpds-motion-celebration-sparkle",
};

/** @returns {{line: number, property: string, reason: string}[]} */
export function lintMotion(css) {
	const violations = [];
	// Declarations anywhere in the text (also one-line rules), values may span lines up to ';' or '}'.
	const decl = /(?:^|[{;\s])(-{0,2}[a-z][a-z0-9-]*)\s*:([^;{}]*)/g;
	for (const m of css.matchAll(decl)) {
		const property = m[1];
		if (!MOTION_PROP.test(property)) continue;
		const at = m.index + m[0].indexOf(property);
		const end = m.index + m[0].length;
		// lint-allow may sit anywhere on the declaration's last line (e.g. a trailing comment).
		const lineEnd = css.indexOf("\n", end);
		if (css.slice(at, lineEnd === -1 ? css.length : lineEnd).includes("lint-allow:")) continue;
		const line = css.slice(0, at).split("\n").length;
		const value = m[2];
		for (const t of value.matchAll(RAW_TIME)) {
			if (Number(t[1]) !== 0) violations.push({ line, property, reason: `raw time ${t[0]}` });
		}
		if (RAW_EASE.test(value)) violations.push({ line, property, reason: "raw easing (use var(--vpds-motion-easing))" });
		if (LOOP.test(value)) violations.push({ line, property, reason: "infinite animation (nothing loops)" });
		const refs = [...value.matchAll(CELEBRATION_REF)].map((r) => r[0]);
		const owner = CELEBRATION_OWNER[property];
		if (refs.length && refs.some((r) => r !== owner)) {
			violations.push({ line, property, reason: "celebration token outside the Celebration overlay" });
		}
		if (owner && !refs.includes(owner)) {
			violations.push({ line, property, reason: `celebration overlay must use ${owner}` });
		}
	}
	return violations;
}

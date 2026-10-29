/** Tiny class-name joiner: drops falsy entries, joins with a single space. */
export function cx(...parts: Array<string | false | null | undefined>): string {
  return parts.filter(Boolean).join(' ')
}

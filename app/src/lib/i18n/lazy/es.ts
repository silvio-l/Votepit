// Lazily imported by ../context.tsx so this language's dictionaries form one
// chunk of their own instead of growing the main bundle.
export default import.meta.glob('../dictionaries/*.es.ts', { eager: true }) as Record<
  string,
  { default: Record<string, string> }
>

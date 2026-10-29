/**
 * Display name for a plan key the core does not know the tier names of (the
 * tier table lives in the cloud layer): capitalises the first letter only.
 */
export function planLabel(plan: string): string {
  return plan.charAt(0).toUpperCase() + plan.slice(1)
}

import type { ReactNode } from 'react'

interface PageShellProps {
  children: ReactNode
  header?: ReactNode
}

export function PageShell({ children, header }: PageShellProps) {
  return (
    <div className="min-h-screen">
      {header}
      <main className="vp-container py-8">{children}</main>
    </div>
  )
}

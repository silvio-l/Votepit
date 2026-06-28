import { Button } from './Button'

interface PaginationProps {
  page: number
  totalPages: number
  onChange: (page: number) => void
}

export function Pagination({ page, totalPages, onChange }: PaginationProps) {
  const hasPrev = page > 1
  const hasNext = page < totalPages

  return (
    <div className="flex items-center justify-center gap-4">
      <Button
        variant="ghost"
        onClick={() => onChange(page - 1)}
        disabled={!hasPrev}
        aria-label="Vorherige Seite"
      >
        ← Zurück
      </Button>

      <span className="text-[13px] text-vp-text-secondary font-inter">
        Seite {page} von {totalPages}
      </span>

      <Button
        variant="ghost"
        onClick={() => onChange(page + 1)}
        disabled={!hasNext}
        aria-label="Nächste Seite"
      >
        Weiter →
      </Button>
    </div>
  )
}

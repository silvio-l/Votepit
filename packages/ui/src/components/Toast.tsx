import { AnimatePresence, motion, useReducedMotion } from 'framer-motion'
import { useEffect } from 'react'

export type ToastType = 'success' | 'error' | 'info'

interface ToastProps {
  message: string
  type?: ToastType
  onClose?: () => void
}

const typeClasses: Record<ToastType, string> = {
  success: 'bg-vp-vote-up text-white',
  error: 'bg-vp-vote-down text-white',
  info: 'bg-vp-ink text-vp-on-ink',
}

export function Toast({ message, type = 'info', onClose }: ToastProps) {
  const reduceMotion = useReducedMotion()

  // Auto-close after 5s
  useEffect(() => {
    if (!onClose) return
    const timer = setTimeout(onClose, 5000)
    return () => clearTimeout(timer)
  }, [onClose])

  return (
    <div
      className="fixed bottom-6 left-1/2 -translate-x-1/2 z-[100]"
      role="status"
      aria-live="polite"
    >
      <AnimatePresence>
        <motion.div
          initial={reduceMotion ? false : { y: 40, opacity: 0 }}
          animate={{ y: 0, opacity: 1 }}
          exit={reduceMotion ? undefined : { y: 40, opacity: 0 }}
          transition={{ type: 'spring', stiffness: 300, damping: 28 }}
          className={[
            'flex items-center gap-3',
            'rounded-vp-md px-4 py-3',
            'shadow-lg',
            'text-[14px] font-inter font-medium',
            typeClasses[type],
          ].join(' ')}
        >
          <span>{message}</span>
          {onClose && (
            <button
              type="button"
              onClick={onClose}
              className="ml-2 opacity-70 hover:opacity-100 transition-opacity cursor-pointer"
              aria-label="Benachrichtigung schließen"
            >
              ✕
            </button>
          )}
        </motion.div>
      </AnimatePresence>
    </div>
  )
}

import { motion, useReducedMotion } from 'framer-motion'
import type { ButtonHTMLAttributes, ReactNode } from 'react'

export type ButtonVariant = 'primary' | 'secondary' | 'ghost'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  children: ReactNode
}

const variantClasses: Record<ButtonVariant, string> = {
  primary:
    'bg-vp-ink text-vp-on-ink hover:opacity-90',
  secondary:
    'border border-vp-border-subtle text-vp-ink bg-transparent hover:bg-black/5',
  ghost:
    'text-vp-accent bg-transparent hover:opacity-80',
}

export function Button({
  variant = 'primary',
  children,
  className = '',
  disabled,
  ...props
}: ButtonProps) {
  const reduceMotion = useReducedMotion()

  return (
    <motion.button
      whileTap={reduceMotion || disabled ? undefined : { scale: 0.96 }}
      disabled={disabled}
      className={[
        'inline-flex items-center justify-center gap-2',
        'px-6 py-3',
        'text-[13px] font-medium font-inter',
        'rounded-vp-md',
        'cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed',
        'transition-opacity duration-150',
        variantClasses[variant],
        className,
      ]
        .filter(Boolean)
        .join(' ')}
      {...(props as object)}
    >
      {children}
    </motion.button>
  )
}

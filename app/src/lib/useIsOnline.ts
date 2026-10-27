import { useSyncExternalStore } from 'react'

function subscribe(onChange: () => void): () => void {
  window.addEventListener('online', onChange)
  window.addEventListener('offline', onChange)
  return () => {
    window.removeEventListener('online', onChange)
    window.removeEventListener('offline', onChange)
  }
}

function readOnline(): boolean {
  return typeof navigator === 'undefined' ? true : navigator.onLine
}

/** Live browser connectivity: true while online (or when it cannot be known). */
export function useIsOnline(): boolean {
  return useSyncExternalStore(subscribe, readOnline, () => true)
}

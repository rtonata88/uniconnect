import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export type Theme = 'light' | 'dark' | 'system'

interface ThemeState {
  theme: Theme
  setTheme: (theme: Theme) => void
}

const media = () => window.matchMedia?.('(prefers-color-scheme: dark)')

export function resolvedTheme(theme: Theme): 'light' | 'dark' {
  if (theme === 'system') return media()?.matches ? 'dark' : 'light'
  return theme
}

export function applyTheme(theme: Theme) {
  const resolved = resolvedTheme(theme)
  const root = document.documentElement
  root.classList.toggle('dark', resolved === 'dark')
  root.style.colorScheme = resolved
  document
    .querySelector('meta[name="theme-color"]')
    ?.setAttribute('content', resolved === 'dark' ? '#0a0a0a' : '#ffffff')
}

/** Light / dark / system theme, persisted in localStorage (no inline script needed: CSP-safe). */
export const useTheme = create<ThemeState>()(
  persist(
    (set) => ({
      theme: 'system',
      setTheme: (theme) => {
        applyTheme(theme)
        set({ theme })
      },
    }),
    {
      name: 'uc-theme',
      onRehydrateStorage: () => (state) => applyTheme(state?.theme ?? 'system'),
    },
  ),
)

media()?.addEventListener?.('change', () => applyTheme(useTheme.getState().theme))

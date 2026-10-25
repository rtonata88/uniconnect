import path from 'node:path'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@': path.resolve(__dirname, './src') } },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
    coverage: {
      provider: 'v8',
      reporter: ['text-summary', 'json-summary', 'cobertura'],
      reportsDirectory: '../docs/testing/results/frontend-coverage',
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/components/ui/**', 'src/**/*.test.*', 'src/test/**', 'src/sw*.ts', 'src/main.tsx'],
    },
  },
})

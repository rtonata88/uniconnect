// MOB-2 / NFR-8: ask Chrome itself whether the app is installable (Page.getInstallabilityErrors).
// Lighthouse 12 removed its PWA category, so this is the installability evidence.
import { createRequire } from 'node:module'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const require = createRequire(path.join(path.dirname(fileURLToPath(import.meta.url)), '../frontend/package.json'))
const { chromium } = require('@playwright/test')
const [base = 'https://localhost', out] = process.argv.slice(2)

const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] })
const page = await (await browser.newContext({ ignoreHTTPSErrors: true })).newPage()
await page.goto(`${base}/login`)
await page.waitForFunction(() => navigator.serviceWorker?.controller || navigator.serviceWorker?.ready, null, { timeout: 15000 })
await page.waitForTimeout(1500)
const cdp = await page.context().newCDPSession(page)
const { installabilityErrors } = await cdp.send('Page.getInstallabilityErrors')
const manifest = await (await page.request.get(`${base}/manifest.webmanifest`)).json()
const sw = await page.evaluate(async () => (await navigator.serviceWorker.getRegistration())?.active?.state ?? 'none')
// offline start: the shell must come from the service worker cache
await page.context().setOffline(true)
await page.reload()
const offlineShell = await page.getByRole('button', { name: 'Sign in' }).isVisible().catch(() => false)
await browser.close()

const result = {
  installable: installabilityErrors.length === 0,
  installability_errors: installabilityErrors,
  service_worker: sw,
  opens_offline: offlineShell,
  manifest: { name: manifest.name, display: manifest.display, start_url: manifest.start_url, icons: manifest.icons.map((i) => `${i.sizes}${i.purpose ? ' ' + i.purpose : ''}`) },
}
if (out) fs.writeFileSync(out, JSON.stringify(result, null, 2))
console.log(JSON.stringify(result))

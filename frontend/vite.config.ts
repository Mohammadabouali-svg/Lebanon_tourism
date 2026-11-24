import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Where the Laravel API is reachable from the Vite server. Inside Docker that
// is the nginx service; outside Docker it is the port nginx publishes.
const apiTarget = process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8080'

// Requests for these paths are forwarded to Laravel. The browser only ever
// talks to one origin (http://localhost:5173), exactly like in production
// where nginx serves both the app and the API. One origin means no CORS
// setup and no third-party-cookie problems for the session cookie.
const proxied = ['/api', '/sanctum', '/storage']

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    // `@/components/Button` instead of `../../../components/Button`.
    alias: { '@': new URL('./src', import.meta.url).pathname },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    watch: process.env.VITE_USE_POLLING === 'true' ? { usePolling: true, interval: 300 } : undefined,
    proxy: Object.fromEntries(
      // `xfwd` adds X-Forwarded-* headers so Laravel sees the real client IP
      // (rate limiting is per IP).
      proxied.map((path) => [path, { target: apiTarget, xfwd: true }]),
    ),
  },
})

import { QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router/dom'
import { queryClient } from '@/lib/queryClient'
import { router } from '@/router'
import './index.css'

/*
 * Entry point. Two providers wrap the whole app:
 *   QueryClientProvider - the server-state cache (lib/queryClient.ts)
 *   RouterProvider      - which page to show for the current URL (router.tsx)
 *
 * StrictMode renders components twice in development to surface bugs such
 * as side effects during render. It has no effect in production builds.
 */
const root = document.getElementById('root')
if (!root) throw new Error('#root element missing in index.html')

createRoot(root).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
)

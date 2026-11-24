import { Link, Outlet } from 'react-router'
import { VerifyEmailBanner } from '@/features/auth/VerifyEmailBanner'
import { Navbar } from './Navbar'

export function PublicLayout() {
  return (
    <div className="flex min-h-screen flex-col">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">
        Skip to content
      </a>
      <Navbar />
      <VerifyEmailBanner />
      <main id="main" className="flex-1">
        <Outlet />
      </main>
      <Footer />
    </div>
  )
}

// Computed once when the module loads: rendering must not depend on the clock.
const currentYear = new Date().getFullYear()

function Footer() {
  return (
    <footer className="border-t border-stone-200 bg-white">
      <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8 text-sm text-stone-500 sm:flex-row sm:items-center sm:justify-between">
        <p>© {currentYear} Lebanon Tourism, a tutorial project.</p>
        <nav aria-label="Footer" className="flex flex-wrap gap-4">
          <Link to="/tours" className="hover:text-stone-800">Tours</Link>
          <Link to="/about" className="hover:text-stone-800">About</Link>
          <Link to="/register/operator" className="hover:text-stone-800">List your tours</Link>
        </nav>
      </div>
    </footer>
  )
}

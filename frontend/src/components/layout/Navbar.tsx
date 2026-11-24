import { useState } from 'react'
import { Link, NavLink, useNavigate } from 'react-router'
import { Button, ButtonLink } from '@/components/ui/Button'
import { useCurrentUser, useLogout } from '@/features/auth/hooks'
import { cn } from '@/lib/cn'
import type { User } from '@/lib/types'

interface NavItem {
  to: string
  label: string
}

/** Links depend on the role. This is navigation convenience, not access control. */
function linksFor(user: User | null): NavItem[] {
  const links: NavItem[] = [
    { to: '/tours', label: 'Tours' },
    { to: '/about', label: 'About' },
  ]
  if (user?.role === 'traveler') links.push({ to: '/bookings', label: 'My bookings' })
  if (user?.role === 'operator') links.push({ to: '/operator', label: 'Operator area' })
  if (user?.role === 'admin') links.push({ to: '/admin', label: 'Admin' })
  return links
}

const linkClass = ({ isActive }: { isActive: boolean }) =>
  cn('rounded-lg px-3 py-2 text-sm font-medium', isActive ? 'bg-brand-50 text-brand-800' : 'text-stone-700 hover:bg-stone-100')

export function Navbar() {
  const { user } = useCurrentUser()
  const logout = useLogout()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const links = linksFor(user)

  function handleLogout() {
    logout.mutate(undefined, { onSuccess: () => navigate('/', { replace: true }) })
  }

  const accountActions = user ? (
    <>
      <NavLink to="/account" className={linkClass} onClick={() => setOpen(false)}>
        {user.name.split(' ')[0]}
      </NavLink>
      <Button variant="secondary" size="sm" loading={logout.isPending} onClick={handleLogout}>
        Log out
      </Button>
    </>
  ) : (
    <>
      <NavLink to="/login" className={linkClass} onClick={() => setOpen(false)}>
        Log in
      </NavLink>
      <ButtonLink to="/register" size="sm" onClick={() => setOpen(false)}>
        Sign up
      </ButtonLink>
    </>
  )

  return (
    <header className="border-b border-stone-200 bg-white">
      <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
        <Link to="/" className="flex items-center gap-2 font-semibold text-brand-900">
          <img src="/favicon.svg" alt="" className="size-8" />
          Lebanon Tourism
        </Link>

        <nav aria-label="Main" className="hidden items-center gap-1 md:flex">
          {links.map((link) => (
            <NavLink key={link.to} to={link.to} className={linkClass}>
              {link.label}
            </NavLink>
          ))}
        </nav>
        <div className="hidden items-center gap-2 md:flex">{accountActions}</div>

        <button
          type="button"
          className="rounded-lg p-2 text-stone-700 hover:bg-stone-100 md:hidden"
          aria-expanded={open}
          aria-controls="mobile-menu"
          aria-label={open ? 'Close menu' : 'Open menu'}
          onClick={() => setOpen((value) => !value)}
        >
          <svg viewBox="0 0 24 24" className="size-6" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
            {open ? <path d="M6 6l12 12M18 6L6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
          </svg>
        </button>
      </div>

      {open && (
        <div id="mobile-menu" className="border-t border-stone-200 px-4 py-3 md:hidden">
          <nav aria-label="Main" className="flex flex-col gap-1">
            {links.map((link) => (
              <NavLink key={link.to} to={link.to} className={linkClass} onClick={() => setOpen(false)}>
                {link.label}
              </NavLink>
            ))}
          </nav>
          <div className="mt-3 flex items-center gap-2 border-t border-stone-100 pt-3">{accountActions}</div>
        </div>
      )}
    </header>
  )
}

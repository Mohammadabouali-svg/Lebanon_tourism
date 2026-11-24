import { Link, NavLink, Outlet, useNavigate } from 'react-router'
import { Button } from '@/components/ui/Button'
import { useCurrentUser, useLogout } from '@/features/auth/hooks'
import { VerifyEmailBanner } from '@/features/auth/VerifyEmailBanner'
import { cn } from '@/lib/cn'

export interface DashboardNavItem {
  to: string
  label: string
  /** Match only the exact path (for the dashboard home link). */
  end?: boolean
}

/**
 * Layout of the operator and admin areas: a sidebar on large screens, a
 * horizontally scrolling tab bar on phones.
 */
export function DashboardLayout({ title, items }: { title: string; items: DashboardNavItem[] }) {
  const { user } = useCurrentUser()
  const logout = useLogout()
  const navigate = useNavigate()

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    cn(
      'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium',
      isActive ? 'bg-brand-700 text-white' : 'text-stone-700 hover:bg-stone-100',
    )

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[15rem_1fr]">
      <aside className="border-b border-stone-200 bg-white lg:min-h-screen lg:border-b-0 lg:border-r">
        <div className="flex items-center justify-between gap-2 px-4 py-4">
          <Link to="/" className="flex items-center gap-2 font-semibold text-brand-900">
            <img src="/favicon.svg" alt="" className="size-7" />
            <span>
              {title}
              <span className="block text-xs font-normal text-stone-500">Lebanon Tourism</span>
            </span>
          </Link>
        </div>
        <nav aria-label={title} className="flex gap-1 overflow-x-auto px-3 pb-3 lg:flex-col lg:overflow-visible">
          {items.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={linkClass}>
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="hidden border-t border-stone-100 px-4 py-4 text-sm lg:block">
          <p className="font-medium text-stone-800">{user?.name}</p>
          <p className="truncate text-stone-500">{user?.email}</p>
          <div className="mt-3 flex gap-2">
            <Link to="/account" className="text-brand-700 hover:underline">Account</Link>
            <span className="text-stone-300">·</span>
            <Link to="/" className="text-brand-700 hover:underline">Public site</Link>
          </div>
          <Button
            variant="secondary"
            size="sm"
            className="mt-3 w-full"
            loading={logout.isPending}
            onClick={() => logout.mutate(undefined, { onSuccess: () => navigate('/', { replace: true }) })}
          >
            Log out
          </Button>
        </div>
      </aside>
      <div className="min-w-0">
        <VerifyEmailBanner />
        <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

import { Outlet, ScrollRestoration } from 'react-router'

/** The outermost route: shared by every page, public or dashboard. */
export function RootLayout() {
  return (
    <>
      {/* Scroll to the top on navigation, restore the position on "back". */}
      <ScrollRestoration />
      <Outlet />
    </>
  )
}

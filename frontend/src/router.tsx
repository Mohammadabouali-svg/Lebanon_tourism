import { createBrowserRouter } from 'react-router'
import { DashboardLayout, type DashboardNavItem } from '@/components/layout/DashboardLayout'
import { PublicLayout } from '@/components/layout/PublicLayout'
import { RootLayout } from '@/components/layout/RootLayout'
import { AccountPage } from '@/features/account/AccountPage'
import { ConfirmEmailChangePage } from '@/features/account/ConfirmEmailChangePage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { RequireApprovedOperator, RequireAuth } from '@/features/auth/guards'
import { LoginPage } from '@/features/auth/LoginPage'
import { RegisterPage } from '@/features/auth/RegisterPage'
import { ResetPasswordPage } from '@/features/auth/ResetPasswordPage'
import { VerifyEmailPage } from '@/features/auth/VerifyEmailPage'
import { BookingPage } from '@/features/bookings/BookingPage'
import { MyBookingsPage } from '@/features/bookings/MyBookingsPage'
import { TourDetailPage } from '@/features/tours/TourDetailPage'
import { ToursPage } from '@/features/tours/ToursPage'
import { AboutPage } from '@/pages/AboutPage'
import { HomePage } from '@/pages/HomePage'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { RouteErrorPage } from '@/pages/RouteErrorPage'

/*
 * Every page of the app, in one place.
 *
 * Nesting = shared layout and shared checks. A child route renders inside its
 * parent's <Outlet />. So wrapping routes in <RequireAuth roles={['admin']} />
 * protects all of them at once, just like route groups in Laravel.
 *
 * Operator and admin pages are loaded *lazily*: their JavaScript is split
 * into separate files that the browser downloads only when someone opens
 * those areas. Travelers never download the admin code.
 */

const operatorNav: DashboardNavItem[] = [
  { to: '/operator', label: 'Dashboard', end: true },
  { to: '/operator/tours', label: 'My tours' },
  { to: '/operator/bookings', label: 'Bookings' },
]

const adminNav: DashboardNavItem[] = [
  { to: '/admin', label: 'Dashboard', end: true },
  { to: '/admin/users', label: 'Users' },
  { to: '/admin/operators', label: 'Operators' },
  { to: '/admin/tours', label: 'Tours' },
  { to: '/admin/bookings', label: 'Bookings' },
  { to: '/admin/discounts', label: 'Discounts' },
  { to: '/admin/catalog', label: 'Catalog' },
]

export const router = createBrowserRouter([
  {
    element: <RootLayout />,
    errorElement: <RouteErrorPage />,
    children: [
      {
        element: <PublicLayout />,
        children: [
          { index: true, element: <HomePage /> },
          { path: 'tours', element: <ToursPage /> },
          { path: 'tours/:id', element: <TourDetailPage /> },
          { path: 'about', element: <AboutPage /> },

          { path: 'login', element: <LoginPage /> },
          { path: 'register', element: <RegisterPage /> },
          { path: 'register/operator', element: <RegisterPage kind="operator" /> },
          { path: 'forgot-password', element: <ForgotPasswordPage /> },
          { path: 'reset-password', element: <ResetPasswordPage /> },
          { path: 'verify-email', element: <VerifyEmailPage /> },
          // Public: the link from the e-mail may be opened while logged out.
          { path: 'confirm-email-change', element: <ConfirmEmailChangePage /> },

          {
            element: <RequireAuth roles={['traveler']} requireVerified />,
            children: [
              { path: 'tours/:id/departures/:departureId/book', element: <BookingPage /> },
              { path: 'bookings', element: <MyBookingsPage /> },
            ],
          },
          {
            element: <RequireAuth />,
            children: [{ path: 'account', element: <AccountPage /> }],
          },

          { path: '*', element: <NotFoundPage /> },
        ],
      },

      {
        path: 'operator',
        element: <RequireAuth roles={['operator']} requireVerified />,
        children: [
          {
            element: <DashboardLayout title="Operator area" items={operatorNav} />,
            children: [
              {
                element: <RequireApprovedOperator />,
                children: [
                  { index: true, lazy: async () => ({ Component: (await import('@/features/operator/OperatorDashboardPage')).OperatorDashboardPage }) },
                  { path: 'tours', lazy: async () => ({ Component: (await import('@/features/operator/OperatorToursPage')).OperatorToursPage }) },
                  { path: 'tours/new', lazy: async () => ({ Component: (await import('@/features/operator/TourFormPage')).TourFormPage }) },
                  { path: 'tours/:id/edit', lazy: async () => ({ Component: (await import('@/features/operator/TourFormPage')).TourFormPage }) },
                  { path: 'bookings', lazy: async () => ({ Component: (await import('@/features/operator/OperatorBookingsPage')).OperatorBookingsPage }) },
                  { path: '*', element: <NotFoundPage /> },
                ],
              },
            ],
          },
        ],
      },

      {
        path: 'admin',
        element: <RequireAuth roles={['admin']} requireVerified />,
        children: [
          {
            element: <DashboardLayout title="Administration" items={adminNav} />,
            children: [
              { index: true, lazy: async () => ({ Component: (await import('@/features/admin/AdminDashboardPage')).AdminDashboardPage }) },
              { path: 'users', lazy: async () => ({ Component: (await import('@/features/admin/AdminUsersPage')).AdminUsersPage }) },
              { path: 'operators', lazy: async () => ({ Component: (await import('@/features/admin/AdminOperatorsPage')).AdminOperatorsPage }) },
              { path: 'tours', lazy: async () => ({ Component: (await import('@/features/admin/AdminToursPage')).AdminToursPage }) },
              { path: 'bookings', lazy: async () => ({ Component: (await import('@/features/admin/AdminBookingsPage')).AdminBookingsPage }) },
              { path: 'discounts', lazy: async () => ({ Component: (await import('@/features/admin/AdminDiscountsPage')).AdminDiscountsPage }) },
              { path: 'catalog', lazy: async () => ({ Component: (await import('@/features/admin/AdminCatalogPage')).AdminCatalogPage }) },
              { path: '*', element: <NotFoundPage /> },
            ],
          },
        ],
      },
    ],
  },
])

/*
 * TypeScript mirrors of the Laravel API Resources (backend/app/Http/Resources).
 *
 * If you add a field to a Resource in PHP, add it here too. The compiler then
 * shows every component that should use it. Optional fields (`?`) are the
 * ones the backend only includes when a relation was loaded (`whenLoaded`).
 *
 * Money is always an integer number of cents (`*_cents`). Dates are strings:
 * "2026-11-18" for calendar dates, ISO 8601 ("2026-11-06T15:30:49+00:00")
 * for moments in time.
 */

export type Role = 'traveler' | 'operator' | 'admin'
export type OperatorStatus = 'pending' | 'approved' | 'suspended'
export type TourStatus = 'draft' | 'published' | 'archived'
export type BookingStatus = 'pending' | 'confirmed' | 'cancelled' | 'failed'
export type PaymentMethod = 'wallet' | 'card'
export type DiscountType = 'fixed' | 'percent'
export type WalletTransactionType = 'credit' | 'debit'

export interface OperatorProfile {
  company_name: string
  business_phone: string
  status: OperatorStatus
  approved_at: string | null
}

export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  role: Role
  email_verified: boolean
  /** A requested new address, waiting for its link to be clicked (null if none). */
  pending_email: string | null
  suspended: boolean
  operator?: OperatorProfile | null
  wallet_balance_cents?: number | null
  created_at: string | null
}

export interface Region {
  id: number
  name: string
  cities?: City[]
  cities_count?: number
}

export interface City {
  id: number
  name: string
  region_id: number
  region?: Region
  tours_count?: number
}

export interface Category {
  id: number
  name: string
  tours_count?: number
}

export interface ItineraryItem {
  id: number
  day: number
  title: string
  description: string | null
}

/** One date a tour runs on, with its own capacity (DepartureResource, Exercise 10). */
export interface Departure {
  id: number
  starts_on: string
  ends_on: string
  capacity: number
  booked_spots: number
  available_spots: number
  /** A display hint; the server re-checks everything when booking. */
  is_bookable: boolean
  /** Operator list only. */
  bookings_count?: number
}

export interface Tour {
  id: number
  title: string
  description: string
  meeting_point: string | null
  price_cents: number
  /** The length of every departure of the tour. */
  duration_days: number
  status: TourStatus
  /** Set while an admin's archive locks the status (D19); always null for published tours. */
  moderated_at: string | null
  image_url: string | null
  city?: City
  category?: Category
  operator?: { company_name: string | null }
  itinerary?: ItineraryItem[]
  /** Soonest first; on the catalogue, the tour page and operator/admin lists. */
  upcoming_departures?: Departure[]
  next_departure?: Departure | null
  /** Dates are coming but none has a free spot (the "Sold out" badge). */
  is_sold_out?: boolean
  rating_average?: number | null
  reviews_count?: number
  bookings_count?: number
  created_at: string | null
}

export interface Traveler {
  id: number
  name: string
  email: string
  phone: string | null
}

export interface Booking {
  id: number
  reference: string
  spots: number
  unit_price_cents: number
  subtotal_cents: number
  discount_cents: number
  total_cents: number
  discount_code?: string | null
  payment_method: PaymentMethod
  status: BookingStatus
  /** Stripe's payment page while a card booking waits for payment (Exercise 9); null otherwise. */
  checkout_url: string | null
  can_cancel?: boolean
  paid_at: string | null
  cancelled_at: string | null
  created_at: string | null
  tour?: Tour
  departure?: Departure
  traveler?: Traveler
}

export interface ReviewPhoto {
  id: number
  url: string
}

export interface Review {
  id: number
  rating: number
  comment: string
  /** Up to three, in upload order. */
  photos: ReviewPhoto[]
  author_name?: string
  can_delete: boolean
  created_at: string | null
}

export interface Discount {
  id: number
  code: string
  description: string | null
  type: DiscountType
  value: number
  max_redemptions: number | null
  redemptions_count: number
  starts_at: string | null
  expires_at: string | null
  is_active: boolean
  unavailable_reason: string | null
  created_at: string | null
}

export interface WalletTransaction {
  id: number
  type: WalletTransactionType
  amount_cents: number
  balance_after_cents: number
  description: string
  created_at: string | null
}

/** Laravel's paginated resource collection envelope. */
export interface Paginated<T> {
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: PaginationMeta
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  per_page: number
  to: number | null
  total: number
}

/** A single resource is wrapped in `data`. */
export interface Envelope<T> {
  data: T
}

export interface WalletPage extends Paginated<WalletTransaction> {
  balance_cents: number
}

export interface Quote {
  unit_price_cents: number
  spots: number
  subtotal_cents: number
  discount_cents: number
  total_cents: number
  discount_code: string | null
  available_spots: number
}

export interface ReferenceData {
  categories: Category[]
  regions: Region[]
}

export interface BookingTotals {
  bookings: number
  travelers: number
  revenue_cents: number
}

export interface MonthlyPoint {
  month: number
  bookings: number
  revenue_cents: number
}

export interface OperatorDashboard {
  tours: Record<TourStatus, number>
  totals: BookingTotals
  year: number
  years: number[]
  monthly: MonthlyPoint[]
  upcoming_departures: { departure: Departure; tour: Tour }[]
}

export interface AdminDashboard {
  users: { travelers: number; operators: number; admins: number }
  pending_operators: number
  published_tours: number
  totals: BookingTotals
  year: number
  years: number[]
  monthly: MonthlyPoint[]
}

/*
 * One admin audit event (AuditEventResource, Exercise 12). `changes` holds only
 * what the action changed: {field: {from, to}} for status changes, or
 * {amount_cents, wallet_transaction_id} for a wallet credit. `subject.label` is
 * null when the subject no longer exists (a deleted tour).
 */
export type AuditAction =
  | 'admin.created'
  | 'operator.approved'
  | 'operator.suspended'
  | 'tour.status_changed'
  | 'user.suspended'
  | 'user.unsuspended'
  | 'wallet.credited'

export interface AuditChange {
  from: string | boolean | null
  to: string | boolean | null
}

export interface AuditEvent {
  id: number
  action: AuditAction
  actor: { id: number; name: string; email: string }
  subject: { type: 'user' | 'tour'; id: number; label: string | null }
  changes: Record<string, AuditChange | number> | null
  created_at: string
}

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

export interface Tour {
  id: number
  title: string
  description: string
  meeting_point: string | null
  price_cents: number
  capacity: number
  booked_spots: number
  available_spots: number
  starts_on: string
  ends_on: string
  duration_days: number
  status: TourStatus
  is_bookable: boolean
  /** Set while an admin's archive locks the status (D19); always null for published tours. */
  moderated_at: string | null
  image_url: string | null
  city?: City
  category?: Category
  operator?: { company_name: string | null }
  itinerary?: ItineraryItem[]
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
  can_cancel?: boolean
  paid_at: string | null
  cancelled_at: string | null
  created_at: string | null
  tour?: Tour
  traveler?: Traveler
}

export interface Review {
  id: number
  rating: number
  comment: string
  photo_url: string | null
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
  upcoming_departures: Tour[]
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

import { StatusBadge } from '@/components/ui/Badge'
import { TBody, THead, Table, Td, Th, Tr } from '@/components/ui/Table'
import { formatDate, formatDateTime } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { Booking } from '@/lib/types'

/** Booking list for operators (their tours) and admins (everything). */
export function BookingsTable({ bookings, showOperator = false }: { bookings: Booking[]; showOperator?: boolean }) {
  return (
    <Table>
      <THead>
        <tr>
          <Th>Reference</Th>
          <Th>Tour</Th>
          <Th>Traveler</Th>
          <Th className="text-right">Spots</Th>
          <Th className="text-right">Total</Th>
          <Th>Payment</Th>
          <Th>Status</Th>
          <Th>Booked</Th>
        </tr>
      </THead>
      <TBody>
        {bookings.map((booking) => (
          <Tr key={booking.id}>
            <Td className="font-mono text-xs">{booking.reference}</Td>
            <Td>
              <span className="font-medium text-stone-900">{booking.tour?.title}</span>
              {booking.tour && <span className="block text-xs text-stone-500">Departs {formatDate(booking.tour.starts_on)}</span>}
              {showOperator && booking.tour?.operator?.company_name && (
                <span className="block text-xs text-stone-500">{booking.tour.operator.company_name}</span>
              )}
            </Td>
            <Td>
              {booking.traveler?.name}
              <span className="block text-xs text-stone-500">{booking.traveler?.email}</span>
              {booking.traveler?.phone && <span className="block text-xs text-stone-500">{booking.traveler.phone}</span>}
            </Td>
            <Td className="text-right">{booking.spots}</Td>
            <Td className="whitespace-nowrap text-right">
              {formatMoney(booking.total_cents)}
              {booking.discount_code && <span className="block text-xs text-brand-700">{booking.discount_code}</span>}
            </Td>
            <Td className="capitalize">{booking.payment_method}</Td>
            <Td><StatusBadge status={booking.status} /></Td>
            <Td className="whitespace-nowrap text-xs">{formatDateTime(booking.created_at)}</Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

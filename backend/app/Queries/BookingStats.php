<?php

namespace App\Queries;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;

/*
| Dashboard numbers, shared by the operator dashboard (their tours only) and
| the admin dashboard (everything).
|
| A "query object" like this keeps reporting SQL out of the controllers and
| gives both dashboards exactly the same definitions ("revenue" means the
| total of confirmed bookings, in both places).
|
| The aggregation happens in Postgres (COUNT, SUM, GROUP BY). Loading every
| booking into PHP to add them up would get slower with every new booking.
*/
final class BookingStats
{
    private function __construct(private readonly ?int $operatorId) {}

    public static function forOperator(int $operatorId): self
    {
        return new self($operatorId);
    }

    public static function global(): self
    {
        return new self(null);
    }

    /** @return array{bookings: int, travelers: int, revenue_cents: int} */
    public function totals(): array
    {
        $row = $this->confirmedBookings()
            ->toBase()
            ->selectRaw('COUNT(*) AS bookings, COALESCE(SUM(spots), 0) AS travelers, COALESCE(SUM(total_cents), 0) AS revenue_cents')
            ->first();

        return [
            'bookings' => (int) $row->bookings,
            'travelers' => (int) $row->travelers,
            'revenue_cents' => (int) $row->revenue_cents,
        ];
    }

    /**
     * Bookings and revenue per month of the given year. Months without
     * bookings are included with zeros, so charts always get 12 bars.
     *
     * @return list<array{month: int, bookings: int, revenue_cents: int}>
     */
    public function monthly(int $year): array
    {
        $rows = $this->confirmedBookings()
            ->toBase()
            ->whereYear('created_at', $year)
            ->selectRaw('EXTRACT(MONTH FROM created_at)::int AS month, COUNT(*) AS bookings, SUM(total_cents) AS revenue_cents')
            ->groupByRaw('EXTRACT(MONTH FROM created_at)')
            ->get()
            ->keyBy('month');

        return array_map(fn (int $month) => [
            'month' => $month,
            'bookings' => (int) ($rows[$month]->bookings ?? 0),
            'revenue_cents' => (int) ($rows[$month]->revenue_cents ?? 0),
        ], range(1, 12));
    }

    /** @return list<int> years that have bookings, newest first, always including this year */
    public function years(): array
    {
        $years = $this->confirmedBookings()
            ->toBase()
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM created_at)::int AS year')
            ->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->push((int) now()->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $years;
    }

    private function confirmedBookings(): Builder
    {
        return Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->when($this->operatorId, fn (Builder $query, int $operatorId) => $query->whereHas(
                'tour',
                fn (Builder $tour) => $tour->where('operator_id', $operatorId),
            ));
    }
}

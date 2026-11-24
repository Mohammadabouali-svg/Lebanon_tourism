<?php

namespace App\Models;

use App\Enums\DiscountType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'description', 'type', 'value', 'max_redemptions',
    'starts_at', 'expires_at', 'is_active',
])]
class Discount extends Model
{
    use HasFactory;

    /** In-memory defaults, mirroring the database (see User::$attributes). */
    protected $attributes = [
        'description' => null,
        'max_redemptions' => null,
        'redemptions_count' => 0,
        'starts_at' => null,
        'expires_at' => null,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'integer',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Codes are case-insensitive for customers: "summer10" works too. */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtoupper(trim($value)));
    }

    /**
     * Why a code cannot be used right now, or null if it can.
     *
     * Returning the reason (instead of a plain bool) lets the API tell the
     * customer *why* their code was refused.
     */
    public function unavailableReason(CarbonInterface $now): ?string
    {
        return match (true) {
            ! $this->is_active => 'This discount code is no longer active.',
            $this->starts_at !== null && $now->lt($this->starts_at) => 'This discount code is not valid yet.',
            $this->expires_at !== null && $now->gte($this->expires_at) => 'This discount code has expired.',
            $this->max_redemptions !== null && $this->redemptions_count >= $this->max_redemptions => 'This discount code has been fully redeemed.',
            default => null,
        };
    }

    /**
     * The amount taken off a subtotal, in cents. Never more than the subtotal.
     *
     * Percentages are rounded half-up using integer arithmetic only:
     * intdiv(x + 50, 100) rounds x / 100 to the nearest whole cent.
     */
    public function amountOffCents(int $subtotalCents): int
    {
        $off = match ($this->type) {
            DiscountType::Fixed => $this->value,
            DiscountType::Percent => intdiv($subtotalCents * $this->value + 50, 100),
        };

        return min($off, $subtotalCents);
    }
}

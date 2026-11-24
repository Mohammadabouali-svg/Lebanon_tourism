<?php

namespace Tests\Unit;

use App\Enums\DiscountType;
use App\Models\Discount;
use App\Support\CsvExport;
use App\Support\Money;
use App\Support\Search;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/*
| Unit tests check one small piece of logic in isolation: no HTTP, no
| database. They run in milliseconds, so edge cases are cheap to cover.
*/
class MoneyAndDiscountTest extends TestCase
{
    public function test_percent_discounts_round_half_up_to_the_cent(): void
    {
        $discount = new Discount(['type' => DiscountType::Percent, 'value' => 15]);

        $this->assertSame(1_500, $discount->amountOffCents(10_000));
        $this->assertSame(150, $discount->amountOffCents(999));   // 149.85 → 150
        $this->assertSame(1, $discount->amountOffCents(7));       // 1.05 → 1
    }

    public function test_a_discount_is_never_larger_than_the_subtotal(): void
    {
        $this->assertSame(4_000, (new Discount(['type' => DiscountType::Fixed, 'value' => 9_999]))->amountOffCents(4_000));
        $this->assertSame(4_000, (new Discount(['type' => DiscountType::Percent, 'value' => 100]))->amountOffCents(4_000));
    }

    public function test_codes_are_normalised_to_upper_case(): void
    {
        $this->assertSame('SUMMER25', (new Discount(['code' => ' summer25 ']))->code);
    }

    public function test_money_is_formatted_without_floating_point(): void
    {
        $this->assertSame('$1,234.05', Money::format(123_405));
        $this->assertSame('$0.99', Money::format(99));
        $this->assertSame('-$5.00', Money::format(-500));
    }

    /** @return array<string, array{string, string}> */
    public static function csvCells(): array
    {
        return [
            'formula' => ['=SUM(A1:A9)', "'=SUM(A1:A9)"],
            'plus' => ['+33 1 23', "'+33 1 23"],
            'minus' => ['-2+3', "'-2+3"],
            'at' => ['@cmd', "'@cmd"],
            'normal text' => ['Maya Khoury', 'Maya Khoury'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('csvCells')]
    public function test_csv_cells_cannot_become_spreadsheet_formulas(string $input, string $expected): void
    {
        $this->assertSame($expected, CsvExport::sanitize($input));
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $this->assertSame('%100\%\_off%', Search::containsPattern(' 100%_off '));
    }
}

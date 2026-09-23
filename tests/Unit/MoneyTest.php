<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_formats_cents_as_us_dollars(): void
    {
        $this->assertSame('$3,000.00', Money::format(300000));
        $this->assertSame('$0.05', Money::format(5));
        $this->assertSame('-$450.00', Money::format(-45000));
    }

    #[DataProvider('dollarInputs')]
    public function test_converts_dollars_to_cents_without_float_drift(string|float|int $dollars, int $cents): void
    {
        $this->assertSame($cents, Money::toCents($dollars));
    }

    public static function dollarInputs(): array
    {
        return [
            'string' => ['19.99', 1999],
            'float' => [0.1 + 0.2, 30],
            'integer' => [500, 50000],
        ];
    }

    public function test_agreement_math_matches_the_approved_example(): void
    {
        // $3,000 set-up fee, NAR2026 (15%), 10% deposit: see docs/sprint-0-review-1.md
        $setup = Money::toCents(3000);
        $implementation = $setup - Money::percentOf($setup, 15);
        $deposit = Money::percentOf($implementation, 10);

        $this->assertSame(255000, $implementation);
        $this->assertSame(25500, $deposit);
        $this->assertSame(229500, $implementation - $deposit);
    }
}

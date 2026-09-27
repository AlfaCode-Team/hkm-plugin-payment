<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;

#[CoversClass(Money::class)]
#[CoversClass(Market::class)]
#[CoversClass(PhoneNumber::class)]
final class MoneyTest extends TestCase
{
    /** @return list<array{string|int|float, string, int, string}> */
    public static function amounts(): array
    {
        return [
            [5000, 'UGX', 5000, '5000'],
            ['5000', 'UGX', 5000, '5000'],
            ['5000.00', 'UGX', 5000, '5000'],
            ['12.5', 'USD', 1250, '12.50'],
            [12.5, 'USD', 1250, '12.50'],
            [0.1 + 0.2, 'KES', 30, '0.30'],
            ['0.05', 'USD', 5, '0.05'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_major_amounts_become_exact_minor_units(string|int|float $major, string $currency, int $minor, string $back): void
    {
        $money = Money::ofMajor($major, $currency);

        self::assertSame($minor, $money->minor);
        self::assertSame($back, $money->toMajor());
    }

    public function test_more_decimals_than_the_currency_has_are_refused_not_rounded(): void
    {
        $this->expectException(\DomainException::class);
        Money::ofMajor('5000.5', 'UGX');
    }

    public function test_garbage_and_negative_amounts_are_refused(): void
    {
        foreach (['-5', 'abc', '1e3', ''] as $bad) {
            try {
                Money::ofMajor($bad, 'UGX');
                self::fail("accepted [{$bad}]");
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_a_reported_balance_is_floored_to_the_currency(): void
    {
        self::assertSame(2503899, Money::ofMajorFloor(2503899.02, 'UGX')->minor);
        self::assertSame(25099, Money::ofMajorFloor('250.999', 'USD')->minor);
    }

    public function test_the_drc_defaults_to_cdf_and_rejects_foreign_currencies(): void
    {
        self::assertSame('CDF', Market::of('CD')->currency);
        self::assertSame('USD', Market::of('cd', 'usd')->currency);

        $this->expectException(\DomainException::class);
        Market::of('UG', 'USD');
    }

    public function test_phone_numbers_normalise_but_never_guess_a_country(): void
    {
        $ug = Market::of('UG');

        self::assertSame('+256712345678', PhoneNumber::forMarket('256 712 345-678', $ug)->value);
        self::assertSame('+256712345678', PhoneNumber::forMarket('00256712345678', $ug)->value);

        $this->expectException(\DomainException::class);
        PhoneNumber::forMarket('0712345678', $ug);
    }
}

<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * What a movement will cost in provider fees, BEFORE it happens.
 *
 * Fees differ per mobile-money network, and the network is often only known
 * once the provider has the payment. So a quote lists the fee on every
 * network the market prices (`fees`), with the range (`minFeeMinor` /
 * `maxFeeMinor`); `feeMinor` is set only when that range is a single value —
 * a network was named, or every network costs the same.
 *
 * `source` per line: "account" (this business's agreed rate,
 * MARZPAY_COLLECTION_FEE_PERCENT) or "published" (the provider's public
 * pricing as of `publishedOn`). A quote is an estimate for display: the fee
 * actually charged is the one recorded on the payment (PaymentDTO::$feeMinor).
 *
 * `available` is false when no fee is published for that amount here (below
 * the minimum, above the highest band, or a product the market lacks).
 */
final readonly class FeeQuoteDTO
{
    /**
     * @param list<array{network: string, fee_minor: int, fee: string, rate: string, source: string}> $fees
     */
    public function __construct(
        public string $direction,
        public string $country,
        public string $currency,
        public int $amountMinor,
        public string $amount,
        public ?string $network,
        public array $fees,
        public ?int $feeMinor,
        public ?string $fee,
        public ?int $minFeeMinor,
        public ?int $maxFeeMinor,
        public bool $available,
        public string $publishedOn,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'direction'     => $this->direction,
            'country'       => $this->country,
            'currency'      => $this->currency,
            'amount'        => $this->amount,
            'amount_minor'  => $this->amountMinor,
            'network'       => $this->network,
            'fee'           => $this->fee,
            'fee_minor'     => $this->feeMinor,
            'min_fee_minor' => $this->minFeeMinor,
            'max_fee_minor' => $this->maxFeeMinor,
            'fees'          => $this->fees,
            'available'     => $this->available,
            'published_on'  => $this->publishedOn,
        ];
    }
}

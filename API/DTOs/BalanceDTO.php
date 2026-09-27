<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * A business wallet balance for one country, in INTEGER MINOR UNITS.
 *
 * `availableMinor` is the spendable MAIN wallet of the selected currency — what
 * a payout draws on. `wallets` lists every currency the country has (the DRC
 * has both CDF and USD), so one call can read both without switching.
 */
final readonly class BalanceDTO
{
    /**
     * @param array<string, array{available_minor: int, card_minor: ?int}> $wallets currency → balances
     */
    public function __construct(
        public string $provider,
        public string $country,
        public string $currency,
        public int $availableMinor,
        public ?int $cardMinor,
        public array $wallets,
        public bool $sandbox,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider'        => $this->provider,
            'country'         => $this->country,
            'currency'        => $this->currency,
            'available_minor' => $this->availableMinor,
            'card_minor'      => $this->cardMinor,
            'wallets'         => $this->wallets,
            'sandbox'         => $this->sandbox,
        ];
    }
}

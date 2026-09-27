<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * Pay out into a bank account (MarzPay: Uganda).
 *
 * A payout in every other respect: the payout permission, the payout caps
 * (the caps are per currency and shared with mobile-money payouts), one live
 * payout per subject, `payout.*` events carrying `method: bank_transfer`.
 *
 * Per SKILL.md, the provider debits amount + its charge from the business wallet at once
 * and refunds it if the transfer fails. `bankName` must be spelt as the
 * provider's bank list spells it (MarzPayServiceContract::banks()); validating
 * the account first (validateBankAccount()) is recommended.
 *
 * `metadata` is kept on the payment row only: MarzPay's bank-transfer API
 * accepts none.
 */
final readonly class BankTransferDTO
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string|int|float $amount,
        public string $bankName,
        public string $accountNumber,
        public string $accountName,
        public ?string $branch = null,
        public ?string $country = null,
        public ?string $currency = null,
        public ?string $description = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $metadata = [],
        public ?string $provider = null,
        public bool $exclusive = true,
    ) {
    }

    /**
     * @param array<string, mixed> $input amount, bank_name, bank_account_number, bank_account_name,
     *                                    bank_branch, country, currency, description, subject_type,
     *                                    subject_id, metadata — MarzPay's own field names
     */
    public static function fromArray(array $input): self
    {
        $amount = $input['amount'] ?? '';
        $str    = static fn(mixed $v): ?string => \is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        return new self(
            amount:        \is_int($amount) || \is_float($amount) ? $amount : (string) $amount,
            bankName:      $str($input['bank_name'] ?? null) ?? '',
            accountNumber: $str($input['bank_account_number'] ?? null) ?? '',
            accountName:   $str($input['bank_account_name'] ?? null) ?? '',
            branch:        $str($input['bank_branch'] ?? null),
            country:       $str($input['country'] ?? null),
            currency:      $str($input['currency'] ?? null),
            description:   $str($input['description'] ?? null),
            subjectType:   $str($input['subject_type'] ?? null),
            subjectId:     $str($input['subject_id'] ?? null),
            metadata:      \is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
        );
    }
}

<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * The account a bank transfer is paid into.
 *
 * The bank is named, not coded — MarzPay identifies banks by the names its
 * /bank-transfer/banks list returns. The account number is kept exactly as
 * given apart from spaces and dashes; a leading zero is significant.
 */
final readonly class BankAccount
{
    private function __construct(
        public string $bankName,
        public string $accountNumber,
        public string $accountName,
        public ?string $branch,
    ) {
    }

    /** @throws \DomainException naming the first problem — see problems() for all of them */
    public static function of(string $bankName, string $accountNumber, string $accountName, ?string $branch = null): self
    {
        $problems = self::problems($bankName, $accountNumber, $accountName, $branch);
        if ($problems !== []) {
            throw new \DomainException(reset($problems));
        }

        return new self(
            trim($bankName),
            self::cleanNumber($accountNumber),
            self::cleanName($accountName),
            $branch !== null && trim($branch) !== '' ? trim($branch) : null,
        );
    }

    /**
     * Every problem with these details, keyed by the field a form would show
     * it under (bank_name, bank_account_number, bank_account_name, bank_branch).
     *
     * @return array<string, string>
     */
    public static function problems(string $bankName, string $accountNumber, string $accountName, ?string $branch = null): array
    {
        $problems = [];
        if (trim($bankName) === '' || mb_strlen(trim($bankName)) > 100) {
            $problems['bank_name'] = 'The bank name is required (at most 100 characters).';
        }
        if (preg_match('/^[A-Za-z0-9]{4,34}$/', self::cleanNumber($accountNumber)) !== 1) {
            $problems['bank_account_number'] = 'The account number must be 4 to 34 letters or digits.';
        }
        $name = self::cleanName($accountName);
        if ($name === '' || mb_strlen($name) > 150) {
            $problems['bank_account_name'] = 'The account holder name is required (at most 150 characters).';
        }
        if ($branch !== null && mb_strlen(trim($branch)) > 100) {
            $problems['bank_branch'] = 'The branch name may be at most 100 characters.';
        }

        return $problems;
    }

    /** Rebuild from storage without re-validating. */
    public static function fromStorage(string $bankName, string $accountNumber, string $accountName, ?string $branch): self
    {
        return new self($bankName, $accountNumber, $accountName, $branch);
    }

    private static function cleanNumber(string $number): string
    {
        return preg_replace('/[\s\-]/', '', $number) ?? '';
    }

    private static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /** "••••6421" — for logs and anywhere the full number is not needed. */
    public function maskedNumber(): string
    {
        return str_repeat('•', max(0, \strlen($this->accountNumber) - 4)) . substr($this->accountNumber, -4);
    }
}

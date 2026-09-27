<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

use Plugins\Payment\Domain\Entities\Payment;

/**
 * A payment as other modules see it. Amounts are INTEGER MINOR UNITS
 * (`amountMinor`) plus the major-unit decimal string (`amount`) for display.
 */
final readonly class PaymentDTO
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $reference,
        public string $direction,
        public string $method,
        public string $provider,
        public string $status,
        public int $amountMinor,
        public string $amount,
        public string $currency,
        public string $country,
        public ?string $phoneNumber,
        public ?string $description,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $metadata,
        public ?string $providerUuid,
        public ?string $providerTransactionId,
        public ?string $redirectUrl,
        public ?string $failureCode,
        public ?string $failureMessage,
        public string $createdAt,
        public ?string $settledAt,
        public ?string $bankName = null,
        public ?string $bankAccountNumber = null,
        public ?string $bankAccountName = null,
        public ?string $bankBranch = null,
    ) {
    }

    public static function from(Payment $p): self
    {
        return new self(
            reference:             (string) $p->reference(),
            direction:             $p->direction()->value,
            method:                $p->method()->value,
            provider:              $p->provider(),
            status:                $p->status()->value,
            amountMinor:           $p->amount()->minor,
            amount:                $p->amount()->toMajor(),
            currency:              $p->amount()->currency,
            country:               $p->market()->country,
            phoneNumber:           $p->phone()?->value,
            description:           $p->description(),
            subjectType:           $p->subjectType(),
            subjectId:             $p->subjectId(),
            metadata:              $p->metadata(),
            providerUuid:          $p->providerUuid(),
            providerTransactionId: $p->providerTransactionId(),
            redirectUrl:           $p->redirectUrl(),
            failureCode:           $p->failureCode(),
            failureMessage:        $p->failureMessage(),
            createdAt:             $p->createdAt()->format(\DateTimeInterface::RFC3339),
            settledAt:             $p->settledAt()?->format(\DateTimeInterface::RFC3339),
            bankName:              $p->bankAccount()?->bankName,
            bankAccountNumber:     $p->bankAccount()?->accountNumber,
            bankAccountName:       $p->bankAccount()?->accountName,
            bankBranch:            $p->bankAccount()?->branch,
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reference'               => $this->reference,
            'direction'               => $this->direction,
            'method'                  => $this->method,
            'provider'                => $this->provider,
            'status'                  => $this->status,
            'amount'                  => $this->amount,
            'amount_minor'            => $this->amountMinor,
            'currency'                => $this->currency,
            'country'                 => $this->country,
            'phone_number'            => $this->phoneNumber,
            'description'             => $this->description,
            'subject_type'            => $this->subjectType,
            'subject_id'              => $this->subjectId,
            'metadata'                => $this->metadata,
            'provider_uuid'           => $this->providerUuid,
            'provider_transaction_id' => $this->providerTransactionId,
            'redirect_url'            => $this->redirectUrl,
            'failure_code'            => $this->failureCode,
            'failure_message'         => $this->failureMessage,
            'created_at'              => $this->createdAt,
            'settled_at'              => $this->settledAt,
            'bank_name'               => $this->bankName,
            'bank_account_number'     => $this->bankAccountNumber,
            'bank_account_name'       => $this->bankAccountName,
            'bank_branch'             => $this->bankBranch,
        ];
    }

    /**
     * What an UNAUTHENTICATED status poll may see — enough for a checkout page
     * to show progress, nothing that identifies the payer or the provider
     * records. The reference is an unguessable UUID v4, but a leaked link must
     * still not hand out a phone number.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'reference'    => $this->reference,
            'status'       => $this->status,
            'amount'       => $this->amount,
            'currency'     => $this->currency,
            'method'       => $this->method,
            'redirect_url' => $this->status === 'pending' ? $this->redirectUrl : null,
            'settled_at'   => $this->settledAt,
        ];
    }
}

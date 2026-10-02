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
        // Since 1.1.0 — for an operator's view of the payment. Trailing and
        // defaulted, so every existing `new PaymentDTO(...)` still compiles.
        /** The provider's OWN reference (MarzPay's, as its dashboard shows it). */
        public ?string $providerReference = null,
        public ?string $previousStatus = null,
        /** Identity userId of whoever started it; null for a guest. */
        public ?string $initiatedBy = null,
        public ?string $lastCheckedAt = null,
        /** When the current status was announced to listeners; null = still in the outbox. */
        public ?string $notifiedAt = null,
        public int $notifyAttempts = 0,
        // Since 1.2.0 — the provider's fee and the network that carried it.
        /** mtn, airtel, mpesa, orange, vodacom, … or "card", as the provider reported it. */
        public ?string $network = null,
        /** The provider's fee in minor units; null until it is known. */
        public ?int $feeMinor = null,
        public ?string $fee = null,
        /** "customer" (added on top of the amount) | "business" (taken from it; every payout) */
        public ?string $feePaidBy = null,
        /**
         * The effect on the business wallet once the fee is known: what a
         * collection CREDITS (amount, less a fee the business bore) or what a
         * payout DEBITS (amount + fee). Null while the fee is unknown.
         */
        public ?int $walletAmountMinor = null,
        /** The administrator who approved or rejected a withdrawal that waited for approval. */
        public ?string $reviewedBy = null,
        public ?string $reviewedAt = null,
        /** Why an admin must check this payment with the provider; null = nothing to check. It settled regardless. */
        public ?string $flagReason = null,
        public ?string $flaggedAt = null,
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
            providerReference:     $p->providerReference(),
            previousStatus:        $p->previousStatus()?->value,
            initiatedBy:           $p->initiatedBy(),
            lastCheckedAt:         $p->lastCheckedAt()?->format(\DateTimeInterface::RFC3339),
            notifiedAt:            $p->notifiedAt()?->format(\DateTimeInterface::RFC3339),
            notifyAttempts:        $p->notifyAttempts(),
            network:               $p->network(),
            feeMinor:              $p->fee()?->minor,
            fee:                   $p->fee()?->toMajor(),
            feePaidBy:             $p->feePaidBy(),
            walletAmountMinor:     $p->walletEffect()?->minor,
            reviewedBy:            $p->reviewedBy(),
            reviewedAt:            $p->reviewedAt()?->format(\DateTimeInterface::RFC3339),
            flagReason:            $p->flagReason(),
            flaggedAt:             $p->flaggedAt()?->format(\DateTimeInterface::RFC3339),
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** A withdrawal recorded but not sent: an administrator has to approve it. */
    public function isAwaitingApproval(): bool
    {
        return $this->status === 'requested';
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
            'provider_reference'      => $this->providerReference,
            'previous_status'         => $this->previousStatus,
            'initiated_by'            => $this->initiatedBy,
            'last_checked_at'         => $this->lastCheckedAt,
            'notified_at'             => $this->notifiedAt,
            'notify_attempts'         => $this->notifyAttempts,
            'network'                 => $this->network,
            'fee'                     => $this->fee,
            'fee_minor'               => $this->feeMinor,
            'fee_paid_by'             => $this->feePaidBy,
            'wallet_amount_minor'     => $this->walletAmountMinor,
            'reviewed_by'             => $this->reviewedBy,
            'reviewed_at'             => $this->reviewedAt,
            'flag_reason'             => $this->flagReason,
            'flagged_at'              => $this->flaggedAt,
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

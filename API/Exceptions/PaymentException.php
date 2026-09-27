<?php

declare(strict_types=1);

namespace Plugins\Payment\API\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\HttpStatusAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\Payment\Support\Messages;

/**
 * Every expected failure of the payment services.
 *
 * `layer` holds the STABLE code a client or a calling module branches on (the
 * kernel's error envelope publishes it as `error.code`). The message is a
 * translated sentence SAFE TO SHOW THE PERSON PAYING — it never contains the
 * provider's own wording, which can describe the business's account ("IP not
 * whitelisted", "insufficient balance"). That wording is kept in
 * `providerMessage` for logs and admin screens, next to `providerCode`
 * (e.g. MarzPay's INSUFFICIENT_BALANCE).
 */
final class PaymentException extends ServiceException implements HttpStatusAware
{
    public const REJECTED             = 'payment.rejected';
    public const PROVIDER_UNAVAILABLE = 'payment.provider_unavailable';
    public const OUTCOME_UNKNOWN      = 'payment.outcome_unknown';
    public const UNKNOWN_PROVIDER     = 'payment.unknown_provider';
    public const NOT_FOUND            = 'payment.not_found';
    public const PAYOUT_IN_FLIGHT     = 'payment.payout_in_flight';
    public const ALREADY_PENDING      = 'payment.already_pending';
    public const ALREADY_PAID         = 'payment.already_paid';
    public const PAYOUT_LIMIT         = 'payment.payout_limit';
    public const THROTTLED            = 'payment.throttled';
    public const PHONE_NOT_FOUND      = 'payment.phone_not_found';
    public const PHONE_NOT_VERIFIED   = 'payment.phone_not_verified';
    public const PHONE_LIMIT          = 'payment.phone_limit';
    public const LOOKUP_LIMIT         = 'payment.lookup_limit';

    /**
     * Provider codes a PAYER can act on → the message key and default shown.
     * Anything else gets the generic line.
     */
    private const PAYER_MESSAGES = [
        'VALIDATION_ERROR'     => ['rejected_invalid', 'The payment details were not accepted. Check them and try again.'],
        'INVALID_PHONE_NUMBER' => ['rejected_phone', 'This phone number cannot be used for this payment.'],
    ];

    /**
     * @param array<string, mixed>               $context
     * @param array<string, list<string>|string> $providerErrors
     */
    private function __construct(
        string $code,
        string $message,
        private readonly int $status,
        array $context = [],
        public readonly ?string $providerCode = null,
        public readonly ?string $providerMessage = null,
        public readonly array $providerErrors = [],
        public readonly ?string $reference = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, layer: $code, context: $context, previous: $previous);
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /** The stable machine code (same value as `layer`). */
    public function code(): string
    {
        return $this->layer;
    }

    /**
     * The provider refused. Nothing moved.
     *
     * @param array<string, list<string>|string> $providerErrors
     */
    public static function rejected(
        ?string $reference,
        string $providerCode,
        string $providerMessage,
        array $providerErrors = [],
        ?\Throwable $previous = null,
    ): self {
        if ($providerCode === 'PENDING_WITHDRAWAL_EXISTS') {
            return new self(
                self::PAYOUT_IN_FLIGHT,
                Messages::get('payout_in_flight', 'Another payout is still being processed. Try again once it completes.'),
                409,
                ['reference' => $reference, 'provider_code' => $providerCode],
                $providerCode, $providerMessage, $providerErrors, $reference, $previous,
            );
        }

        [$key, $default] = self::PAYER_MESSAGES[$providerCode]
            ?? ['rejected', 'The payment could not be processed. Please try again later.'];

        return new self(
            self::REJECTED,
            Messages::get($key, $default),
            422,
            ['reference' => $reference, 'provider_code' => $providerCode],
            $providerCode, $providerMessage, $providerErrors, $reference, $previous,
        );
    }

    /** The provider could not be reached; a LEDGER payment stays pending and settles itself. */
    public static function providerUnavailable(?string $reference, \Throwable $previous): self
    {
        return new self(
            self::PROVIDER_UNAVAILABLE,
            Messages::get(
                'provider_unavailable',
                'The payment provider could not be reached. The payment is pending and will be confirmed automatically.',
            ),
            502,
            ['reference' => $reference],
            reference: $reference,
            previous: $previous,
        );
    }

    /**
     * The provider answered in a way that proves nothing either way (e.g. it
     * already knows this reference). The payment stays pending until confirmed.
     */
    public static function outcomeUnknown(string $reference, string $providerCode, string $providerMessage, ?\Throwable $previous = null): self
    {
        return new self(
            self::OUTCOME_UNKNOWN,
            Messages::get('outcome_unknown', 'The payment status is not known yet. It will be confirmed automatically.'),
            409,
            ['reference' => $reference, 'provider_code' => $providerCode],
            $providerCode, $providerMessage, reference: $reference, previous: $previous,
        );
    }

    /**
     * The provider could not be reached for an operation the payments ledger
     * does NOT track (bills, airtime, bank transfers, …). Its outcome is
     * unknown and nothing will confirm it automatically.
     */
    public static function unreachable(\Throwable $previous): self
    {
        return new self(
            self::PROVIDER_UNAVAILABLE,
            Messages::get(
                'provider_unreachable',
                'The payment provider could not be reached. Check the transaction status before trying again.',
            ),
            502,
            previous: $previous,
        );
    }

    public static function unknownProvider(string $provider): self
    {
        return new self(
            self::UNKNOWN_PROVIDER,
            Messages::get('unknown_provider', 'Unknown payment provider [:provider].', ['provider' => $provider]),
            404,
            ['provider' => $provider],
        );
    }

    public static function notFound(string $reference): self
    {
        return new self(
            self::NOT_FOUND,
            Messages::get('not_found', 'Payment [:reference] was not found.', ['reference' => $reference]),
            404,
            ['reference' => $reference],
        );
    }

    /** A live payment already occupies this subject. `reference` names it. */
    public static function alreadyPending(string $existingReference): self
    {
        return new self(
            self::ALREADY_PENDING,
            Messages::get('already_pending', 'A payment for this is already in progress.'),
            409,
            ['reference' => $existingReference],
            reference: $existingReference,
        );
    }

    public static function alreadyPaid(string $existingReference): self
    {
        return new self(
            self::ALREADY_PAID,
            Messages::get('already_paid', 'This has already been paid.'),
            409,
            ['reference' => $existingReference],
            reference: $existingReference,
        );
    }

    public static function payoutLimit(string $limit, string $currency, string $window): self
    {
        return new self(
            self::PAYOUT_LIMIT,
            Messages::get(
                $window === 'daily' ? 'payout_daily_limit' : 'payout_limit',
                $window === 'daily'
                    ? 'This payout would exceed the daily payout limit of :limit :currency.'
                    : 'Payouts are limited to :limit :currency each.',
                ['limit' => $limit, 'currency' => $currency],
            ),
            422,
            ['limit' => $limit, 'currency' => $currency, 'window' => $window],
        );
    }

    /** No saved number with this id for this owner (another owner's id is "not found" too). */
    public static function phoneNotFound(string $id): self
    {
        return new self(
            self::PHONE_NOT_FOUND,
            Messages::get('phone_not_found', 'That phone number is not saved.'),
            404,
            ['phone_number_id' => $id],
        );
    }

    /** Money is not withdrawn to a number the provider has not verified. */
    public static function phoneNotVerified(string $id, string $status): self
    {
        return new self(
            self::PHONE_NOT_VERIFIED,
            Messages::get(
                $status === 'failed' ? 'phone_failed' : 'phone_not_verified',
                $status === 'failed'
                    ? 'This phone number could not be verified, so money cannot be sent to it.'
                    : 'Verify this phone number before withdrawing to it.',
            ),
            422,
            ['phone_number_id' => $id, 'verification_status' => $status],
        );
    }

    public static function phoneLimit(int $max): self
    {
        return new self(
            self::PHONE_LIMIT,
            Messages::get('phone_limit', 'At most :max phone numbers can be saved. Remove one first.', ['max' => $max]),
            422,
            ['max' => $max],
        );
    }

    /** The daily allowance of subscriber lookups is used up. */
    public static function lookupLimit(int $perDay): self
    {
        return new self(
            self::LOOKUP_LIMIT,
            Messages::get('lookup_limit', 'Too many phone number checks today. Try again tomorrow.'),
            429,
            ['per_day' => $perDay],
        );
    }

    /** Too many provider checks for one payment in a short window. */
    public static function throttled(string $reference): self
    {
        return new self(
            self::THROTTLED,
            Messages::get('throttled', 'Too many requests for this payment. Try again in a few seconds.'),
            429,
            ['reference' => $reference],
            reference: $reference,
        );
    }
}

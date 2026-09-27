<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Gateway;

use Plugins\Payment\Domain\ValueObjects\PhoneVerificationStatus;

/** A subscriber lookup's answer, normalised. */
final readonly class PhoneVerificationResult
{
    public function __construct(
        public PhoneVerificationStatus $status,
        public ?string $registeredName = null,
        public ?string $code = null,
    ) {
    }
}

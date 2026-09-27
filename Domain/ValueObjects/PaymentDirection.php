<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/** Money coming IN from a customer, or going OUT to a recipient. */
enum PaymentDirection: string
{
    case Collection = 'collection';
    case Payout     = 'payout';

    /** The integration-event prefix: payment.* for collections, payout.* for payouts. */
    public function eventPrefix(): string
    {
        return $this === self::Collection ? 'payment' : 'payout';
    }
}

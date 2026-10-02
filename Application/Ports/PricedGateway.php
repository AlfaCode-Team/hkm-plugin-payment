<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Ports;

use Plugins\Payment\Domain\Fees\FeeSchedule;

/**
 * OPTIONAL provider capability: the fees it charges — its published rates
 * with the business's own agreed rates on top. Used to quote a fee before a
 * movement, to account for a fee a provider adds to a collection without
 * naming it, and to flag a reported fee that is not the agreed one.
 */
interface PricedGateway
{
    public function fees(): FeeSchedule;
}

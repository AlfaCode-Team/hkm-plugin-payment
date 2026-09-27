<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Gateway;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

/** A callback failed signature verification — it did not come from the provider. */
final class InvalidSignatureException extends GatewayException
{
}

<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Gateway;

use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\Application\Ports\PaymentGateway;

/** The configured provider drivers, by name, plus the default one. */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    /** @param list<PaymentGateway> $gateways */
    public function __construct(array $gateways, private readonly string $default)
    {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->name()] = $gateway;
        }
    }

    /** @throws PaymentException 404 when the provider is not configured */
    public function get(?string $name = null): PaymentGateway
    {
        $name = strtolower(trim((string) $name));
        $name = $name !== '' ? $name : $this->default;

        return $this->gateways[$name] ?? throw PaymentException::unknownProvider($name);
    }

    public function has(string $name): bool
    {
        return isset($this->gateways[strtolower(trim($name))]);
    }
}

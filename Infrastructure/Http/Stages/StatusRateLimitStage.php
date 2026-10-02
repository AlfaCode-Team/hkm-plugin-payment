<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\Payment\Support\Messages;

/**
 * Route filter `payment.rate_limit:{perMinute}` — a fixed one-minute window per
 * client IP, kept in CachePort. Used on the status poll (60) and the webhook
 * (600: generous — MarzPay sends every callback from few addresses — but a
 * ceiling on how fast anyone can make the plugin write and call out).
 *
 * The plugin registers this alias itself, so its public status route is
 * limited without requiring the SecurityFilters plugin. With no CachePort
 * bound there is nothing to count in, and the stage passes through: rate
 * limiting then belongs to the edge (nginx `limit_req`).
 *
 * Resolved once per worker by the FilterRegistry, so it holds NO request
 * state: the cache and clock are read from the request's container each time.
 */
final class StatusRateLimitStage implements HttpStageContract
{
    public const ALIAS = 'payment.rate_limit';

    private const DEFAULT_PER_MINUTE = 60;

    public function handle(Request $request, callable $next): Response
    {
        $limit     = (int) (($request->attribute('filter_args')[self::ALIAS][0] ?? null) ?? self::DEFAULT_PER_MINUTE);
        $container = $request->container();

        if ($limit <= 0 || $container === null || !$container->has(CachePort::class)) {
            return $next($request);
        }

        /** @var CachePort $cache */
        $cache  = $container->make(CachePort::class);
        $now    = $container->has(ClockPort::class) ? $container->make(ClockPort::class)->timestamp() : time();
        $window = intdiv($now, 60);
        // The limit is part of the key: each route that declares its own limit
        // (status poll 60, webhook 600) counts separately.
        $key    = 'payment:rl:' . $limit . ':' . hash('sha256', (string) $request->ip()) . ':' . $window;

        try {
            if (!$cache->has($key)) {
                $cache->set($key, 0, 120);
            }
            $count = $cache->increment($key);
        } catch (\Throwable) {
            // A cache outage must not take the status page down with it.
            return $next($request);
        }

        if ($count > $limit) {
            return Response::tooManyRequests(
                Messages::get('rate_limited', 'Too many status checks. Try again in a minute.'),
                retryAfter: 60 - ($now % 60),
            );
        }

        return $next($request);
    }
}

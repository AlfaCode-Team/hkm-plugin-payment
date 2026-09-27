<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Payment\API\Contracts\PaymentActivityContract;
use Plugins\Payment\API\DTOs\PaymentEventPage;
use Plugins\Payment\Application\Ports\PaymentJournal;
use Plugins\Payment\Application\Ports\PaymentStore;
use Plugins\Payment\Domain\ValueObjects\PaymentDirection;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;
use Plugins\Payment\Support\Messages;

/**
 * The read side of the activity journal, for an operator's console. Every
 * method needs the payment admin permission, as search() does.
 */
final class PaymentActivityService implements PaymentActivityContract
{
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly PaymentJournal $journal,
        private readonly PaymentStore $store,
        private readonly Identity $identity,
        private readonly string $adminPermission = 'payment:manage',
    ) {
    }

    public function timeline(string $reference): array
    {
        $this->authorize();

        return PaymentReference::isValid($reference) ? $this->journal->forReference($reference) : [];
    }

    public function webhooks(int $page = 1, int $perPage = 25, ?string $outcome = null): PaymentEventPage
    {
        $this->authorize();

        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $result  = $this->journal->webhooks($outcome, $perPage, ($page - 1) * $perPage);

        return new PaymentEventPage($result['items'], $result['total'], $page, $perPage);
    }

    public function statusCounts(?string $direction = null): array
    {
        $this->authorize();

        if ($direction !== null && PaymentDirection::tryFrom($direction) === null) {
            throw new ValidationException(['direction' => Messages::get('validation.direction', 'Direction must be collection or payout.')]);
        }

        $counts = [];
        foreach (PaymentStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        return array_merge($counts, $this->store->statusCounts($direction));
    }

    private function authorize(): void
    {
        if ($this->adminPermission !== '' && !$this->identity->hasPermission($this->adminPermission)) {
            throw new SecurityException(
                Messages::get('forbidden', 'You are not allowed to manage payments.'),
                layer:   'payment.forbidden',
                context: ['permission' => $this->adminPermission],
                code:    403,
            );
        }
    }
}

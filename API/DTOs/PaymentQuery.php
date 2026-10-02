<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * Filters for PaymentServiceContract::search(). Every filter is optional;
 * dates compare against created_at and are inclusive.
 */
final readonly class PaymentQuery
{
    public const MAX_PER_PAGE = 100;

    public int $page;
    public int $perPage;

    public function __construct(
        public ?string $status = null,
        public ?string $direction = null,
        public ?string $provider = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        int $page = 1,
        int $perPage = 25,
        /**
         * Since 1.1.0: one box an operator types into — our reference, the
         * provider's reference or uuid (exact), or part of a phone number.
         */
        public ?string $search = null,
        /** Since 1.2.0: true = only payments an admin must check with the provider; false = none of them. */
        public ?bool $flagged = null,
        /** Since 1.2.0: only one owner's withdrawals (both or neither). */
        public ?string $ownerType = null,
        public ?string $ownerId = null,
    ) {
        $this->page    = max(1, $page);
        $this->perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
    }

    /**
     * From query-string input: status, direction, provider, subject_type,
     * subject_id, from, to (Y-m-d or any strtotime-able date), page, per_page.
     * Unparseable dates are ignored rather than guessed.
     *
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $str  = static fn(mixed $v): ?string => \is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $date = static function (mixed $v) use ($str): ?\DateTimeImmutable {
            $v = $str($v);
            if ($v === null) {
                return null;
            }
            try {
                return new \DateTimeImmutable($v);
            } catch (\Exception) {
                return null;
            }
        };

        return new self(
            status:      $str($input['status'] ?? null),
            direction:   $str($input['direction'] ?? null),
            provider:    $str($input['provider'] ?? null),
            subjectType: $str($input['subject_type'] ?? null),
            subjectId:   $str($input['subject_id'] ?? null),
            from:        $date($input['from'] ?? null),
            to:          $date($input['to'] ?? null),
            page:        (int) ($input['page'] ?? 1),
            perPage:     (int) ($input['per_page'] ?? 25),
            search:      $str($input['search'] ?? $input['q'] ?? null),
            flagged:     isset($input['flagged']) && $str($input['flagged']) !== null
                ? \in_array(strtolower((string) $input['flagged']), ['1', 'true', 'yes', 'on'], true)
                : null,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}

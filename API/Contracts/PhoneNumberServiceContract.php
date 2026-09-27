<?php

declare(strict_types=1);

namespace Plugins\Payment\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Payment\API\DTOs\AddPhoneNumberDTO;
use Plugins\Payment\API\DTOs\PhoneCheckDTO;
use Plugins\Payment\API\DTOs\PhoneNumberDTO;
use Plugins\Payment\API\Exceptions\PaymentException;

/**
 * Checking mobile-money numbers, and keeping each owner's saved numbers — the
 * destinations PaymentServiceContract::withdraw() pays out to.
 *
 * An OWNER is whatever the host application withdraws money for, named by
 * ownerType + ownerId ("user" + "42", "vendor" + "7"). Every call is scoped by
 * it: an id belonging to another owner is "not found". This service does NOT
 * decide who may act for an owner — pass the owner from the authenticated
 * context (the logged-in user, the vendor the session manages), never from
 * request input.
 *
 * NAME LOOKUPS. check(lookupName: true), add(verify: true) and verify() ask
 * the provider who a number is registered to (MarzPay: Uganda; other markets
 * are recorded `unsupported`). Each lookup is billed to the business and
 * reveals a person's registered name, so they are capped per actor per UTC
 * day (PAYMENT_PHONE_LOOKUPS_PER_DAY; the owner for add/verify, the signed-in
 * user for check) when a CachePort is bound.
 */
interface PhoneNumberServiceContract
{
    /**
     * Is this a usable number for the country — and, with $lookupName, who is
     * it registered to? Format problems are an ANSWER (valid: false, error),
     * not an exception.
     *
     * @throws ValidationException unknown country
     * @throws PaymentException    429 lookup_limit, 422 provider refused the business
     *                             (e.g. not subscribed), 502 provider unreachable
     */
    public function check(string $phoneNumber, ?string $country = null, bool $lookupName = false): PhoneCheckDTO;

    /**
     * Save a number for an owner. Saving one the owner already has returns the
     * saved one (with makeDefault applied, and a lookup when it was never
     * verified) rather than failing.
     *
     * @throws ValidationException bad owner, number, country or label
     * @throws PaymentException    422 phone_limit (PAYMENT_PHONE_MAX_PER_OWNER)
     */
    public function add(AddPhoneNumberDTO $dto): PhoneNumberDTO;

    /** @return list<PhoneNumberDTO> the default first, then newest first */
    public function list(string $ownerType, string $ownerId): array;

    public function find(string $ownerType, string $ownerId, string $id): ?PhoneNumberDTO;

    public function defaultFor(string $ownerType, string $ownerId): ?PhoneNumberDTO;

    /**
     * Run the provider's lookup again and record the answer.
     *
     * @throws PaymentException 404 phone_not_found, 429 lookup_limit,
     *                          422 provider refused the business, 502 unreachable
     */
    public function verify(string $ownerType, string $ownerId, string $id): PhoneNumberDTO;

    /**
     * @param ?string $label null or '' removes it
     * @throws ValidationException|PaymentException 404 phone_not_found
     */
    public function rename(string $ownerType, string $ownerId, string $id, ?string $label): PhoneNumberDTO;

    /** @throws PaymentException 404 phone_not_found */
    public function makeDefault(string $ownerType, string $ownerId, string $id): PhoneNumberDTO;

    /**
     * Forget a number. Payments already sent to it keep their own copy.
     *
     * @return bool false when there was no such number
     */
    public function remove(string $ownerType, string $ownerId, string $id): bool;
}

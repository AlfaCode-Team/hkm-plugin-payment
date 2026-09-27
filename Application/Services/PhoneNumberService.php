<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Payment\API\Contracts\PhoneNumberServiceContract;
use Plugins\Payment\API\DTOs\AddPhoneNumberDTO;
use Plugins\Payment\API\DTOs\PhoneCheckDTO;
use Plugins\Payment\API\DTOs\PhoneNumberDTO;
use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\Application\Exceptions\PhoneNumberConflictException;
use Plugins\Payment\Application\Gateway\GatewayRegistry;
use Plugins\Payment\Application\Gateway\PhoneVerificationResult;
use Plugins\Payment\Application\Gateway\ProviderRejectedException;
use Plugins\Payment\Application\Ports\PhoneNumberStore;
use Plugins\Payment\Application\Ports\PhoneVerificationGateway;
use Plugins\Payment\Domain\Entities\SavedPhoneNumber;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;
use Plugins\Payment\Domain\ValueObjects\PhoneVerificationStatus;
use Plugins\Payment\Support\Messages;

/**
 * Checking numbers and keeping each owner's saved ones.
 *
 * Two rules shape it:
 *
 *  1. Everything is scoped by owner. There is no lookup by id alone, so an id
 *     copied from one account reaches nothing from another.
 *  2. A subscriber lookup costs the business money and discloses a person's
 *     registered name, so each one is counted against a daily allowance per
 *     actor. Saving a number never FAILS because a lookup could not run — the
 *     number is kept unverified, with the reason, and can be verified later.
 */
final class PhoneNumberService implements PhoneNumberServiceContract
{
    public function __construct(
        private readonly PhoneNumberStore $store,
        private readonly GatewayRegistry $gateways,
        private readonly TransactionManager $transaction,
        private readonly Identity $identity,
        private readonly ClockPort $clock,
        private readonly ?LoggerPort $logger = null,
        private readonly ?CachePort $cache = null,
        private readonly string $defaultCountry = 'UG',
        private readonly int $maxPerOwner = 10,
        private readonly int $lookupsPerDay = 10,
    ) {
    }

    // ── Checking ──────────────────────────────────────────────────────────────

    public function check(string $phoneNumber, ?string $country = null, bool $lookupName = false): PhoneCheckDTO
    {
        $market = $this->market($country);

        try {
            $phone = PhoneNumber::forMarket($phoneNumber, $market);
        } catch (\DomainException $e) {
            return new PhoneCheckDTO(
                input:   $phoneNumber,
                country: $market->country,
                valid:   false,
                error:   Messages::get('validation.phone', $e->getMessage()),
            );
        }

        if (!$lookupName) {
            return new PhoneCheckDTO($phoneNumber, $market->country, true, $phone->value);
        }

        // Guests share ONE allowance: an anonymous caller must not be able to
        // run name lookups at the business's expense.
        $actor  = 'user:' . ($this->identity->isGuest() ? '' : $this->identity->userId);
        $result = $this->lookup($phone, $market, $actor);

        return new PhoneCheckDTO(
            input:              $phoneNumber,
            country:            $market->country,
            valid:              true,
            phoneNumber:        $phone->value,
            verificationStatus: $result->status->value,
            registeredName:     $result->status === PhoneVerificationStatus::Verified ? $result->registeredName : null,
            verificationCode:   $result->code,
        );
    }

    // ── The owner's saved numbers ─────────────────────────────────────────────

    public function add(AddPhoneNumberDTO $dto): PhoneNumberDTO
    {
        $ownerType = trim($dto->ownerType);
        $ownerId   = trim($dto->ownerId);

        $errors = self::ownerErrors($ownerType, $ownerId);
        $market = null;
        $phone  = null;
        try {
            $market = Market::of($dto->country ?? $this->defaultCountry);
        } catch (\DomainException $e) {
            $errors['country'] = Messages::get('validation.market', $e->getMessage());
        }
        if ($market !== null) {
            if (trim($dto->phoneNumber) === '') {
                $errors['phone_number'] = Messages::get('validation.phone_required', 'A phone number is required.');
            } else {
                try {
                    $phone = PhoneNumber::forMarket($dto->phoneNumber, $market);
                } catch (\DomainException $e) {
                    $errors['phone_number'] = Messages::get('validation.phone', $e->getMessage());
                }
            }
        }
        if ($dto->label !== null && mb_strlen(trim($dto->label)) > 60) {
            $errors['label'] = Messages::get('validation.label', 'The label may be at most 60 characters.');
        }
        if ($errors !== [] || $market === null || $phone === null) {
            throw new ValidationException($errors);
        }

        $existing = $this->store->findByNumber($ownerType, $ownerId, $phone->value);
        if ($existing !== null) {
            return $this->addAgain($existing, $dto);
        }

        $saved = $this->store->forOwner($ownerType, $ownerId);
        if (\count($saved) >= $this->maxPerOwner) {
            throw PaymentException::phoneLimit($this->maxPerOwner);
        }

        $number = SavedPhoneNumber::register($ownerType, $ownerId, $phone, $market, $dto->label, $this->clock->now());
        if ($dto->verify) {
            $this->verifyQuietly($number);
        }

        $hasDefault = $saved !== [] && $saved[0]->isDefault();
        if ($dto->makeDefault || !$hasDefault) {
            $number->makeDefault($this->clock->now());
        }

        try {
            $this->atomically(function () use ($number, $dto, $ownerType, $ownerId): void {
                if ($dto->makeDefault) {
                    $this->store->clearDefault($ownerType, $ownerId);
                }
                $this->store->insert($number);
            });
        } catch (PhoneNumberConflictException $e) {
            if ($e->conflict === PhoneNumberConflictException::DUPLICATE_NUMBER) {
                // Saved concurrently by another request — the same outcome.
                $raced = $this->store->findByNumber($ownerType, $ownerId, $phone->value);
                if ($raced !== null) {
                    return PhoneNumberDTO::from($raced);
                }
            }
            if ($e->conflict === PhoneNumberConflictException::SECOND_DEFAULT && !$dto->makeDefault) {
                // Another number became the default meanwhile; this one is simply not it.
                $number->unmarkDefault($this->clock->now());
                $this->store->insert($number);

                return PhoneNumberDTO::from($number);
            }

            throw $e;
        }

        return PhoneNumberDTO::from($number);
    }

    public function list(string $ownerType, string $ownerId): array
    {
        return array_map(
            static fn(SavedPhoneNumber $n): PhoneNumberDTO => PhoneNumberDTO::from($n),
            $this->store->forOwner(trim($ownerType), trim($ownerId)),
        );
    }

    public function find(string $ownerType, string $ownerId, string $id): ?PhoneNumberDTO
    {
        $number = $this->load($ownerType, $ownerId, $id);

        return $number !== null ? PhoneNumberDTO::from($number) : null;
    }

    public function defaultFor(string $ownerType, string $ownerId): ?PhoneNumberDTO
    {
        $first = $this->store->forOwner(trim($ownerType), trim($ownerId))[0] ?? null;

        return $first !== null && $first->isDefault() ? PhoneNumberDTO::from($first) : null;
    }

    public function verify(string $ownerType, string $ownerId, string $id): PhoneNumberDTO
    {
        $number = $this->load($ownerType, $ownerId, $id) ?? throw PaymentException::phoneNotFound($id);

        $result = $this->lookup($number->phone(), $number->market(), self::ownerActor($number));
        $number->recordVerification($result->status, $result->registeredName, $result->code, $this->clock->now());
        $this->store->save($number);

        return PhoneNumberDTO::from($number);
    }

    public function rename(string $ownerType, string $ownerId, string $id, ?string $label): PhoneNumberDTO
    {
        $number = $this->load($ownerType, $ownerId, $id) ?? throw PaymentException::phoneNotFound($id);

        try {
            $number->rename($label, $this->clock->now());
        } catch (\DomainException $e) {
            throw new ValidationException(['label' => Messages::get('validation.label', $e->getMessage())]);
        }
        $this->store->save($number);

        return PhoneNumberDTO::from($number);
    }

    public function makeDefault(string $ownerType, string $ownerId, string $id): PhoneNumberDTO
    {
        $number = $this->load($ownerType, $ownerId, $id) ?? throw PaymentException::phoneNotFound($id);
        if ($number->isDefault()) {
            return PhoneNumberDTO::from($number);
        }

        $number->makeDefault($this->clock->now());
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->atomically(function () use ($number): void {
                    $this->store->clearDefault($number->ownerType(), $number->ownerId());
                    $this->store->save($number);
                });

                return PhoneNumberDTO::from($number);
            } catch (PhoneNumberConflictException $e) {
                // Two requests swapping the default at once: the UNIQUE index let
                // one win. Try once more against what it wrote.
                if ($attempt >= 2) {
                    throw new ServiceException('payment.phone.default_conflict', layer: 'service.payment', previous: $e);
                }
            }
        }
    }

    public function remove(string $ownerType, string $ownerId, string $id): bool
    {
        $number = $this->load($ownerType, $ownerId, $id);

        return $number !== null && $this->store->delete($number);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** Saving a number the owner already has: apply what was asked, do not fail. */
    private function addAgain(SavedPhoneNumber $existing, AddPhoneNumberDTO $dto): PhoneNumberDTO
    {
        if ($dto->verify && $existing->verification() === PhoneVerificationStatus::Unverified) {
            $this->verifyQuietly($existing);
            $this->store->save($existing);
        }
        if ($dto->makeDefault && !$existing->isDefault()) {
            return $this->makeDefault($existing->ownerType(), $existing->ownerId(), $existing->id());
        }

        return PhoneNumberDTO::from($existing);
    }

    /**
     * Look the number up, recording why when the lookup cannot run. Used while
     * SAVING, which must not fail for want of a lookup.
     */
    private function verifyQuietly(SavedPhoneNumber $number): void
    {
        try {
            $result = $this->lookup($number->phone(), $number->market(), self::ownerActor($number));
        } catch (PaymentException $e) {
            $this->logger?->warning('Phone number saved unverified: the lookup could not run', [
                'phone_number_id' => $number->id(),
                'code'            => $e->code(),
                'provider_code'   => $e->providerCode,
            ]);
            $result = new PhoneVerificationResult(
                PhoneVerificationStatus::Unverified,
                code: $e->providerCode !== null ? 'provider.' . strtolower($e->providerCode) : $e->code(),
            );
        }

        $number->recordVerification($result->status, $result->registeredName, $result->code, $this->clock->now());
    }

    /**
     * One subscriber lookup through the default provider.
     *
     * @throws PaymentException 429 lookup_limit, 422 refused, 502 unreachable
     */
    private function lookup(PhoneNumber $phone, Market $market, string $actor): PhoneVerificationResult
    {
        $gateway = $this->gateways->get(null);
        if (!$gateway instanceof PhoneVerificationGateway || !$gateway->supportsPhoneVerification($market)) {
            return new PhoneVerificationResult(PhoneVerificationStatus::Unsupported);
        }

        $this->spendLookup($actor);

        try {
            return $gateway->verifyPhone($phone, $market);
        } catch (ProviderRejectedException $e) {
            $this->logger?->warning('Phone number lookup refused by the provider', [
                'provider' => $gateway->name(),
                'code'     => $e->errorCode,
                'message'  => $e->getMessage(),
            ]);
            throw PaymentException::rejected(null, $e->errorCode, $e->getMessage(), $e->errors, $e);
        } catch (GatewayException $e) {
            throw PaymentException::unreachable($e);
        }
    }

    /**
     * Count one lookup against the actor's allowance for the current UTC day.
     * With no CachePort there is nowhere to count, and nothing is limited —
     * the same trade the status route's rate limit makes.
     */
    private function spendLookup(string $actor): void
    {
        if ($this->lookupsPerDay <= 0 || $this->cache === null) {
            return;
        }

        $day = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd');
        $key = 'payment:lookup:' . hash('sha256', $actor) . ':' . $day;

        try {
            if (!$this->cache->has($key)) {
                $this->cache->set($key, 0, 2 * 86400);
            }
            $count = $this->cache->increment($key);
        } catch (\Throwable $e) {
            // A cache outage must not stop people saving their numbers.
            $this->logger?->warning('Phone lookup allowance could not be counted', ['error' => $e->getMessage()]);

            return;
        }

        if ($count > $this->lookupsPerDay) {
            throw PaymentException::lookupLimit($this->lookupsPerDay);
        }
    }

    /** @param \Closure(): void $work */
    private function atomically(\Closure $work): void
    {
        $this->transaction->begin();
        try {
            $work();
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $e;
        }
    }

    private function load(string $ownerType, string $ownerId, string $id): ?SavedPhoneNumber
    {
        $id = strtolower(trim($id));

        return $id === '' ? null : $this->store->find($id, trim($ownerType), trim($ownerId));
    }

    /** @return array<string, string> */
    private static function ownerErrors(string $ownerType, string $ownerId): array
    {
        $errors = [];
        foreach (['owner_type' => [$ownerType, 60], 'owner_id' => [$ownerId, 64]] as $field => [$value, $max]) {
            if ($value === '') {
                $errors[$field] = Messages::get('validation.owner', 'The owner is required.');
            } elseif (mb_strlen($value) > $max) {
                $errors[$field] = Messages::get('validation.too_long', 'Must be at most :max characters.', ['max' => $max]);
            }
        }

        return $errors;
    }

    private function market(?string $country): Market
    {
        try {
            return Market::of($country ?? $this->defaultCountry);
        } catch (\DomainException $e) {
            throw new ValidationException(['country' => Messages::get('validation.market', $e->getMessage())]);
        }
    }

    private static function ownerActor(SavedPhoneNumber $number): string
    {
        return 'owner:' . $number->ownerType() . ':' . $number->ownerId();
    }
}

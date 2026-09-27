<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\API\DTOs\AddPhoneNumberDTO;
use Plugins\Payment\API\DTOs\PhoneNumberDTO;
use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\Application\Gateway\GatewayRegistry;
use Plugins\Payment\Application\Services\PhoneNumberService;
use Plugins\Payment\Domain\Rules\NameMatch;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayClient;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayGateway;
use Tests\Unit\Plugins\Payment\Support\ArrayCache;
use Tests\Unit\Plugins\Payment\Support\FakeHttpClient;
use Tests\Unit\Plugins\Payment\Support\FrozenClock;
use Tests\Unit\Plugins\Payment\Support\InMemoryPhoneNumberStore;
use Tests\Unit\Plugins\Payment\Support\MarzPayFixtures as F;
use Tests\Unit\Plugins\Payment\Support\NullDatabase;

/**
 * Checking numbers and the owner's saved numbers, against the real MarzPay
 * driver with only the HTTP transport faked.
 */
#[CoversClass(PhoneNumberService::class)]
#[CoversClass(NameMatch::class)]
final class PhoneNumberServiceTest extends TestCase
{
    private const VERIFY = '/phone-verification/verify';

    private FakeHttpClient $http;
    private InMemoryPhoneNumberStore $store;
    private FrozenClock $clock;
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->http  = new FakeHttpClient();
        $this->store = new InMemoryPhoneNumberStore();
        $this->clock = new FrozenClock();
        $this->cache = new ArrayCache();
    }

    private function service(?Identity $identity = null, int $lookupsPerDay = 10, int $maxPerOwner = 10, bool $cache = true): PhoneNumberService
    {
        $gateway = new MarzPayGateway(
            new MarzPayClient($this->http, 'https://wallet.wearemarz.com/api/v1', 'key', 'secret'),
            $this->clock,
        );

        return new PhoneNumberService(
            store:         $this->store,
            gateways:      new GatewayRegistry([$gateway], 'marzpay'),
            transaction:   new TransactionManager(new NullDatabase()),
            identity:      $identity ?? Identity::asUser('u1'),
            clock:         $this->clock,
            cache:         $cache ? $this->cache : null,
            maxPerOwner:   $maxPerOwner,
            lookupsPerDay: $lookupsPerDay,
        );
    }

    private function add(PhoneNumberService $service, string $phone = '+256712345678', array $overrides = []): PhoneNumberDTO
    {
        return $service->add(new AddPhoneNumberDTO(...array_merge([
            'ownerType'   => 'user',
            'ownerId'     => '42',
            'phoneNumber' => $phone,
        ], $overrides)));
    }

    // ── check ───────────────────────────────────────────────────────────────

    public function test_check_validates_the_format_for_the_market_without_calling_the_provider(): void
    {
        $service = $this->service();

        $ok = $service->check('256 712-345-678');
        self::assertTrue($ok->valid);
        self::assertSame('+256712345678', $ok->phoneNumber);
        self::assertNull($ok->verificationStatus, 'no lookup was asked for');

        $local = $service->check('0712345678');
        self::assertFalse($local->valid, 'a local number is never guessed into international form');
        self::assertNotNull($local->error);

        self::assertFalse($service->check('+254710000000', 'UG')->valid, 'a Kenyan number is not a Ugandan one');
        self::assertTrue($service->check('+254710000000', 'KE')->valid);

        self::assertSame([], $this->http->requests);
    }

    public function test_check_refuses_an_unknown_country(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->check('+256712345678', 'XX');
    }

    public function test_check_can_look_the_subscriber_up(): void
    {
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));

        $result = $this->service()->check('+256712345678', lookupName: true);

        self::assertSame(['phone_number' => '256712345678'], $this->http->sentJson('POST', self::VERIFY), 'documented without the "+"');
        self::assertSame('verified', $result->verificationStatus);
        self::assertSame('MARY NAKAMYA', $result->registeredName);
        self::assertTrue($result->nameMatches('Mary Nakamya'));
        self::assertTrue($result->nameMatches('nakamya mary'));
        self::assertFalse($result->nameMatches('John Okello'));
    }

    public function test_a_market_without_a_lookup_is_unsupported_and_costs_nothing(): void
    {
        $result = $this->service()->check('+254710000000', 'KE', lookupName: true);

        self::assertSame('unsupported', $result->verificationStatus);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->cache->store, 'no allowance spent');
    }

    public function test_lookups_are_capped_per_actor_per_day(): void
    {
        $service = $this->service(lookupsPerDay: 2);
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));

        $service->check('+256712345678', lookupName: true);
        $service->check('+256712345678', lookupName: true);

        try {
            $service->check('+256712345678', lookupName: true);
            self::fail('the third lookup must be refused');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::LOOKUP_LIMIT, $e->code());
            self::assertSame(429, $e->httpStatus());
        }
        self::assertSame(2, $this->http->count('POST', self::VERIFY));

        // Another user has their own allowance…
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));
        $this->service(Identity::asUser('u2'), lookupsPerDay: 2)->check('+256712345678', lookupName: true);

        // …and the next UTC day starts afresh.
        $this->clock->advance(86400);
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));
        self::assertSame('verified', $service->check('+256712345678', lookupName: true)->verificationStatus);
    }

    public function test_guests_share_one_allowance(): void
    {
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));
        $this->service(Identity::guest(), lookupsPerDay: 1)->check('+256712345678', lookupName: true);

        $this->expectException(PaymentException::class);
        $this->service(Identity::guest(), lookupsPerDay: 1)->check('+256712345678', lookupName: true);
    }

    public function test_a_cache_outage_does_not_block_lookups(): void
    {
        $this->cache->broken = true;
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));

        self::assertSame('verified', $this->service(lookupsPerDay: 1)->check('+256712345678', lookupName: true)->verificationStatus);
    }

    public function test_a_business_level_refusal_is_an_error_not_a_verdict_on_the_number(): void
    {
        $this->http->on('POST', self::VERIFY, 403, F::error('SERVICE_NOT_SUBSCRIBED', 'Subscribe to phone verification.'));

        try {
            $this->service()->check('+256712345678', lookupName: true);
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::REJECTED, $e->code());
            self::assertSame('SERVICE_NOT_SUBSCRIBED', $e->providerCode);
            self::assertStringNotContainsString('Subscribe', $e->getMessage(), 'provider wording stays out of the payer-facing message');
        }
    }

    // ── add / list / default ────────────────────────────────────────────────

    public function test_the_first_number_is_verified_and_becomes_the_default(): void
    {
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));
        $service = $this->service();

        $first = $this->add($service, overrides: ['label' => 'My MTN']);

        self::assertTrue($first->isDefault);
        self::assertSame('verified', $first->verificationStatus);
        self::assertSame('MARY NAKAMYA', $first->registeredName);
        self::assertSame('My MTN', $first->label);
        self::assertSame('+256712345678', $first->phoneNumber);
        self::assertSame('+2567•••••678', $first->maskedNumber());

        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256752345678', 'MARY', 'NAKAMYA'));
        $second = $this->add($service, '+256752345678');
        self::assertFalse($second->isDefault);

        $list = $service->list('user', '42');
        self::assertSame([$first->id, $second->id], array_map(static fn(PhoneNumberDTO $n): string => $n->id, $list), 'default first');
        self::assertSame($first->id, $service->defaultFor('user', '42')?->id);
    }

    public function test_make_default_moves_the_default(): void
    {
        $service = $this->service();
        $first   = $this->add($service, overrides: ['verify' => false]);
        $second  = $this->add($service, '+256752345678', ['verify' => false, 'makeDefault' => true]);

        self::assertTrue($second->isDefault);
        self::assertFalse($service->find('user', '42', $first->id)?->isDefault);

        $service->makeDefault('user', '42', $first->id);
        self::assertSame($first->id, $service->defaultFor('user', '42')?->id);
        self::assertCount(1, array_filter($service->list('user', '42'), static fn(PhoneNumberDTO $n): bool => $n->isDefault));
    }

    public function test_saving_a_number_twice_returns_the_saved_one(): void
    {
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));
        $service = $this->service();

        $first = $this->add($service);
        $again = $this->add($service, '256 712 345 678'); // the same number, written differently

        self::assertSame($first->id, $again->id);
        self::assertCount(1, $this->store->rows);
        self::assertSame(1, $this->http->count('POST', self::VERIFY), 'already verified — not looked up again');
    }

    public function test_a_lookup_that_cannot_run_still_saves_the_number_unverified(): void
    {
        $this->http->failOn('POST', self::VERIFY, new GatewayException('timeout'));

        $saved = $this->add($this->service());

        self::assertSame('unverified', $saved->verificationStatus);
        self::assertSame(PaymentException::PROVIDER_UNAVAILABLE, $saved->verificationCode);
        self::assertCount(1, $this->store->rows);
    }

    public function test_a_used_up_allowance_still_saves_the_number_unverified(): void
    {
        $service = $this->service(lookupsPerDay: 1);
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));
        $this->add($service);

        $second = $this->add($service, '+256752345678');

        self::assertSame('unverified', $second->verificationStatus);
        self::assertSame(PaymentException::LOOKUP_LIMIT, $second->verificationCode);
        self::assertSame(1, $this->http->count('POST', self::VERIFY));
    }

    public function test_an_owner_can_save_a_limited_number_of_numbers(): void
    {
        $service = $this->service(maxPerOwner: 2);
        $this->add($service, overrides: ['verify' => false]);
        $this->add($service, '+256752345678', ['verify' => false]);

        try {
            $this->add($service, '+256772345678', ['verify' => false]);
            self::fail('expected the limit');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_LIMIT, $e->code());
        }

        // Another owner is unaffected.
        $this->add($service, '+256772345678', ['verify' => false, 'ownerId' => '43']);
        self::assertCount(3, $this->store->rows);
    }

    public function test_bad_input_is_reported_field_by_field(): void
    {
        try {
            $this->add($this->service(), '0712345678', ['ownerType' => ' ', 'label' => str_repeat('x', 61)]);
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['owner_type', 'phone_number', 'label'], array_keys($e->errors));
        }
        self::assertSame([], $this->store->rows);
    }

    // ── owner scoping ───────────────────────────────────────────────────────

    public function test_another_owner_cannot_reach_a_saved_number(): void
    {
        $service = $this->service();
        $mine    = $this->add($service, overrides: ['verify' => false]);

        self::assertNull($service->find('user', '43', $mine->id));
        self::assertNull($service->find('vendor', '42', $mine->id));
        self::assertFalse($service->remove('user', '43', $mine->id));
        self::assertCount(1, $this->store->rows);

        $this->expectException(PaymentException::class);
        $service->verify('user', '43', $mine->id);
    }

    // ── verify / rename / remove ────────────────────────────────────────────

    public function test_verify_records_a_number_the_provider_does_not_know_as_failed(): void
    {
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678'));
        $service = $this->service();
        $saved   = $this->add($service);
        self::assertSame('MARY NAKAMYA', $saved->registeredName);

        // The SIM changed hands and the number is no longer registered.
        $this->http->on('POST', self::VERIFY, 404, F::error('NOT_FOUND', 'Phone number not found'));
        $again = $service->verify('user', '42', $saved->id);

        self::assertSame('failed', $again->verificationStatus);
        self::assertSame('marzpay.not_found', $again->verificationCode);
        self::assertNull($again->registeredName, "a former subscriber's name must not stay attached");
    }

    public function test_a_success_false_answer_is_a_failed_lookup(): void
    {
        $this->http->on('POST', self::VERIFY, 200, ['success' => false, 'message' => 'Could not verify number']);

        self::assertSame('failed', $this->add($this->service())->verificationStatus);
    }

    public function test_an_unrecognised_verification_word_leaves_the_number_unverified(): void
    {
        $this->http->on('POST', self::VERIFY, 200, F::phoneVerified('256712345678', status: 'pending_review'));

        $saved = $this->add($this->service());

        self::assertSame('unverified', $saved->verificationStatus, 'neither verified nor failed on a word we do not know');
        self::assertSame('marzpay.pending_review', $saved->verificationCode);
        self::assertNull($saved->registeredName);
    }

    public function test_verify_propagates_a_business_refusal_and_leaves_the_number_as_it_was(): void
    {
        $service = $this->service();
        $saved   = $this->add($service, overrides: ['verify' => false]);
        $this->http->on('POST', self::VERIFY, 403, F::error('FORBIDDEN', 'IP not whitelisted'));

        try {
            $service->verify('user', '42', $saved->id);
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame('FORBIDDEN', $e->providerCode);
        }
        self::assertSame('unverified', $service->find('user', '42', $saved->id)?->verificationStatus);
    }

    public function test_rename_and_remove(): void
    {
        $service = $this->service();
        $saved   = $this->add($service, overrides: ['verify' => false]);

        self::assertSame('Airtel', $service->rename('user', '42', $saved->id, '  Airtel ')->label);
        self::assertNull($service->rename('user', '42', $saved->id, '')->label);

        try {
            $service->rename('user', '42', $saved->id, str_repeat('x', 61));
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('label', $e->errors);
        }

        self::assertTrue($service->remove('user', '42', $saved->id));
        self::assertFalse($service->remove('user', '42', $saved->id));
        self::assertNull($service->defaultFor('user', '42'));
    }

    // ── name matching ───────────────────────────────────────────────────────

    public function test_name_matching_ignores_case_order_accents_and_a_middle_name(): void
    {
        self::assertTrue(NameMatch::check('NAKAMYA MARY JANE', 'Mary Nakamya'));
        self::assertTrue(NameMatch::check('MARY NAKAMYA', 'Nakamya, Mary'));
        self::assertTrue(NameMatch::check('ÉLODIE KABILA', 'Elodie Kabila'));
        self::assertTrue(NameMatch::check('OKELLO', 'okello'));

        self::assertFalse(NameMatch::check('MARY NAKAMYA', 'Mary'), 'one shared part is not enough');
        self::assertFalse(NameMatch::check('MARY NAKAMYA', 'Mary Okello'));
        self::assertFalse(NameMatch::check('', 'Mary Nakamya'));
    }
}

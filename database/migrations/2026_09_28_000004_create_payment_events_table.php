<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Payment — the activity journal: what happened to each payment, and every
 * webhook the provider sent.
 *
 * `payments` holds a payment's CURRENT state and its previous status; this
 * holds its history, one row per thing that happened:
 *
 *   payment.created       the row was written, before the provider was called
 *   provider.accepted     the provider took the request (its uuid)
 *   provider.rejected     the provider refused it (code + message)
 *   provider.unreachable  no answer — the outcome is unknown, left pending
 *   status.changed        status_from → status_to, and `via` what proved it
 *   check.unverifiable    the provider's answer did not prove it is THIS
 *                         payment, or reported a different amount
 *   check.contradicted    the provider reports a status the settled payment
 *                         cannot move to (e.g. failed after succeeded)
 *   announce.failed       a listener threw; the outbox will redeliver
 *   webhook.received      one row per callback, with `outcome` and the body
 *
 * A webhook row is written even when it names no payment we know, or its
 * signature is wrong — those are exactly the ones an operator needs to see.
 * `reference` is then whatever the callback claimed (NULL when unreadable).
 *
 * `via` says what drove a status change: create (the provider's create
 * response), webhook, poll (a payer's status poll), check (an operator or a
 * caller asking), reconcile (`payments:reconcile`), expiry (past its TTL).
 *
 * Plain "still pending" checks are NOT journalled: a checkout polls every few
 * seconds, and that would bury the rows that matter.
 *
 * `payload` is the callback body as received, capped — it can carry the
 * payer's phone number, the same personal data `payments` already holds.
 *
 * Ships in database/migrations and database/tenant-template alike; the
 * hasTable() guard makes the two copies safe to apply together.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('payment_events')) {
            return;
        }

        $schema->create('payment_events', static function ($t) {
            $t->id();

            $t->char('reference', 36)->nullable()->comment('OUR payment reference; NULL for a callback naming none');
            $t->string('provider', 20)->comment('marzpay | …');
            $t->string('kind', 40)->comment('payment.created | provider.* | status.changed | check.* | announce.failed | webhook.received');
            $t->string('via', 12)->nullable()->comment('create | webhook | poll | check | reconcile | expiry');
            $t->string('status_from', 12)->nullable();
            $t->string('status_to', 12)->nullable();
            $t->string('outcome', 30)->nullable()->comment('webhook only: applied | already_settled | unknown_payment | invalid_signature | unreadable | throttled | provider_unreachable | no_change');
            $t->string('event_type', 60)->nullable()->comment("webhook only: the provider's own event name");
            $t->string('provider_uuid', 64)->nullable();
            $t->string('detail', 255)->nullable();
            $t->text('payload')->nullable()->comment('webhook body as received, capped');
            $t->dateTime('created_at');

            $t->index(['reference', 'id'], 'idx_payment_events_reference');
            $t->index(['kind', 'created_at'], 'idx_payment_events_kind');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('payment_events');
    }
};

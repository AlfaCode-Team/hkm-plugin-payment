<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Payment — the `payments` ledger.
 *
 * One row per money movement (a collection from a customer or a payout to a
 * recipient), written BEFORE the provider is called and settled once the
 * provider's API confirms the outcome.
 *
 * The same table ships in database/migrations (central) and
 * database/tenant-template (per tenant): the repository writes through the
 * REQUEST's DatabasePort, which is the tenant database on a Tenancy host and
 * the central one otherwise. The hasTable() guard makes the two copies safe to
 * apply together.
 *
 * Money is INTEGER MINOR UNITS (`amount_minor`) — 5,000 UGX is 5000, 12.50 USD
 * is 1250 — never a float.
 *
 * `exclusive_key` is UNIQUE and NULL for every payment that does not occupy its
 * subject; MySQL, PostgreSQL and SQLite all allow any number of NULLs in a
 * unique index. (SQL Server does not — there, replace the index with a
 * filtered one: WHERE exclusive_key IS NOT NULL.)
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('payments')) {
            return;
        }

        $schema->create('payments', static function ($t) {
            $t->id();

            $t->char('reference', 36)->comment('OUR reference — UUID v4, sent to the provider, unique per attempt');
            $t->string('direction', 12)->comment('collection | payout');
            $t->string('method', 20)->comment('mobile_money | card');
            $t->string('provider', 20)->comment('marzpay | …');
            $t->string('status', 12)->default('pending')->comment('pending | succeeded | failed | cancelled | expired | reversed');
            $t->string('previous_status', 12)->nullable()->comment('status before the latest change — announced as previousStatus');

            $t->unsignedBigInteger('amount_minor')->comment('integer minor units of `currency`');
            $t->char('currency', 3);
            $t->char('country', 2);
            $t->string('phone_number', 20)->nullable()->comment('E.164; null for card');
            $t->string('description', 255)->nullable();

            $t->string('subject_type', 60)->nullable()->comment('what the payment is for, e.g. vote.order');
            $t->string('subject_id', 64)->nullable();
            $t->string('exclusive_key', 140)->nullable()->comment('direction:subject_type:subject_id while the payment occupies its subject');
            $t->json('metadata')->nullable()->comment('flat key → value, echoed by the provider');
            $t->json('metadata_pii')->nullable()->comment('metadata keys flagged isPII');

            $t->string('provider_uuid', 64)->nullable()->comment('the provider transaction uuid');
            $t->string('provider_reference', 120)->nullable()->comment('the provider\'s own reference');
            $t->string('provider_transaction_id', 120)->nullable()->comment('telco / card network id');
            $t->string('redirect_url', 500)->nullable()->comment('card checkout page');
            $t->string('failure_code', 60)->nullable();
            $t->string('failure_message', 255)->nullable();

            $t->string('initiated_by', 64)->nullable()->comment('Identity userId; null for a guest');
            $t->dateTime('created_at');
            $t->dateTime('settled_at')->nullable();
            $t->dateTime('last_checked_at')->nullable()->comment('last provider status check');
            $t->dateTime('notified_at')->nullable()->comment('when the current status was announced; NULL = outbox');
            $t->unsignedInteger('notify_attempts')->default(0);
            $t->dateTime('updated_at')->nullable();

            $t->unique(['reference'], 'uniq_payments_reference');
            $t->unique(['exclusive_key'], 'uniq_payments_exclusive');
            $t->index(['notified_at', 'settled_at'], 'idx_payments_outbox');
            $t->index(['provider', 'provider_uuid'], 'idx_payments_provider_uuid');
            $t->index(['status', 'created_at'], 'idx_payments_status_created');
            $t->index(['subject_type', 'subject_id'], 'idx_payments_subject');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('payments');
    }
};

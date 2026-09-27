<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Payment — saved mobile-money numbers.
 *
 * One row per (owner, number): an owner is whatever the host application
 * withdraws money FOR — a user, a vendor, a driver — named by
 * owner_type + owner_id. `phone_id` is the public UUID; the auto-increment id
 * never leaves the database.
 *
 * `default_key` is "owner_type:owner_id" on the owner's default number and
 * NULL on the rest; the UNIQUE index is what keeps it to one default per
 * owner. MySQL, PostgreSQL and SQLite allow any number of NULLs in a unique
 * index. (SQL Server does not — there, make it a filtered index:
 * WHERE default_key IS NOT NULL.)
 *
 * `registered_name` is the subscriber name from the telco lookup: personal
 * data. It is kept only while the number is verified.
 *
 * Ships in database/migrations and database/tenant-template alike; the
 * hasTable() guard makes the two copies safe to apply together.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('payment_phone_numbers')) {
            return;
        }

        $schema->create('payment_phone_numbers', static function ($t) {
            $t->id();

            $t->char('phone_id', 36)->comment('public id — UUID v4');
            $t->string('owner_type', 60)->comment('what the number belongs to, e.g. user');
            $t->string('owner_id', 64);
            $t->string('phone_number', 20)->comment('E.164');
            $t->char('country', 2);
            $t->string('label', 60)->nullable()->comment('the owner\'s own name for it, e.g. "My MTN"');
            $t->string('default_key', 130)->nullable()->comment('owner_type:owner_id while this is the owner\'s default');

            $t->string('verification_status', 12)->default('unverified')->comment('unverified | verified | failed | unsupported');
            $t->string('registered_name', 150)->nullable()->comment('subscriber name from the telco lookup (PII)');
            $t->string('verification_code', 60)->nullable()->comment('why the last lookup did not verify it');
            $t->dateTime('verified_at')->nullable()->comment('when it was last looked up');

            $t->dateTime('created_at');
            $t->dateTime('updated_at')->nullable();

            $t->unique(['phone_id'], 'uniq_payment_phones_id');
            $t->unique(['owner_type', 'owner_id', 'phone_number'], 'uniq_payment_phones_owner_number');
            $t->unique(['default_key'], 'uniq_payment_phones_default');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('payment_phone_numbers');
    }
};

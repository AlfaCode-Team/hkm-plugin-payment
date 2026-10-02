<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Payment — what the provider charged, and on which network.
 *
 *   network      mtn | airtel | mpesa | orange | vodacom | … | card — as the
 *                provider reported it; fees differ per network
 *   fee_minor    the provider's fee, integer minor units of `currency`; NULL
 *                until the provider reports it (or a collection's reported
 *                amount proves it)
 *   fee_paid_by  customer (added on top of what was asked) | business (taken
 *                from the business: every payout, and a collection whose
 *                provider reported exactly the amount asked)
 *
 * Ships in database/migrations and database/tenant-template alike; the
 * hasColumn() guard makes the two copies safe to apply together.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasColumn('payments', 'fee_minor')) {
            return;
        }

        $schema->table('payments', static function ($t) {
            $t->string('network', 30)->nullable()->comment('mobile-money network or card, as the provider reported it');
            $t->unsignedBigInteger('fee_minor')->nullable()->comment('provider fee, minor units of currency');
            $t->string('fee_paid_by', 10)->nullable()->comment('customer | business');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasColumn('payments', 'fee_minor')) {
            return;
        }

        $schema->table('payments', static function ($t) {
            $t->dropColumn('network', 'fee_minor', 'fee_paid_by');
        });
    }
};

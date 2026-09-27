<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Payment — the destination of a bank-transfer payout.
 *
 * NULL on every other payment. `method` gains the value `bank_transfer` (it
 * fits the existing string(20)); nothing else about a row changes.
 *
 * Ships in database/migrations and database/tenant-template alike; the
 * hasColumn() guard makes the two copies safe to apply together.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasColumn('payments', 'bank_account_number')) {
            return;
        }

        $schema->table('payments', static function ($t) {
            $t->string('bank_name', 100)->nullable()->comment('bank transfer: the bank, as the provider names it');
            $t->string('bank_account_number', 40)->nullable();
            $t->string('bank_account_name', 150)->nullable()->comment('account holder');
            $t->string('bank_branch', 100)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasColumn('payments', 'bank_account_number')) {
            return;
        }

        $schema->table('payments', static function ($t) {
            $t->dropColumn('bank_name', 'bank_account_number', 'bank_account_name', 'bank_branch');
        });
    }
};

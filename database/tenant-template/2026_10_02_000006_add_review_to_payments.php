<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Payment — approvals, owners and flags.
 *
 *   reviewed_by / reviewed_at   the admin who approved or rejected a payout that
 *                               waited for approval (PAYMENT_WITHDRAW_APPROVAL=admin)
 *   owner_type / owner_id /     who a withdrawal is for and which saved number it
 *   phone_number_id             pays — re-checked when it is approved; lets the
 *                               owner cancel a request
 *   flag_reason / flagged_at    something did not add up (amount, fee) and an
 *                               admin must check it with the provider; NULL =
 *                               nothing to check. The payment settles regardless.
 *
 * `status` gains two values, `requested` and `rejected`; both fit its
 * existing string(12), so the column itself does not change.
 *
 * Ships in database/migrations and database/tenant-template alike; the
 * hasColumn() guard makes the two copies safe to apply together.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasColumn('payments', 'reviewed_by')) {
            return;
        }

        $schema->table('payments', static function ($t) {
            $t->string('reviewed_by', 64)->nullable()->comment('Identity userId of the admin who approved or rejected the withdrawal');
            $t->dateTime('reviewed_at')->nullable();
            $t->string('owner_type', 60)->nullable()->comment('withdrawals: who the money is for');
            $t->string('owner_id', 64)->nullable();
            $t->char('phone_number_id', 36)->nullable()->comment('withdrawals: the saved number paid');
            $t->string('flag_reason', 255)->nullable()->comment('why an admin must check it with the provider');
            $t->dateTime('flagged_at')->nullable();
            $t->index(['flagged_at'], 'idx_payments_flagged');
            $t->index(['owner_type', 'owner_id'], 'idx_payments_owner');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasColumn('payments', 'reviewed_by')) {
            return;
        }

        $schema->table('payments', static function ($t) {
            $t->dropIndex('idx_payments_flagged', 'idx_payments_owner');
            $t->dropColumn('reviewed_by', 'reviewed_at', 'owner_type', 'owner_id', 'phone_number_id', 'flag_reason', 'flagged_at');
        });
    }
};

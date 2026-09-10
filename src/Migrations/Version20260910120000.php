<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The plugin's schema, described once and for every engine Sylius runs on.
 *
 * Nothing here is SQL. The tables are built on the schema object Doctrine hands to `up()`, and
 * Doctrine derives the statements for the connected engine when the migration runs — `AUTO_INCREMENT`
 * and `LONGTEXT` on MySQL and MariaDB, `SERIAL` and `TEXT` on PostgreSQL, the column comments the
 * ORM needs on each. There is no copy per engine to keep in step, and nothing skips itself.
 *
 * The mapping in `config/doctrine/model/` is the truth this file is held to: an Integration test
 * compares the tables this migration builds with the ones the ORM expects, on PostgreSQL and on
 * MySQL, and fails on any difference. Change the mapping, and this file follows.
 *
 * Identifiers are `IDENTITY` in the mapping rather than `AUTO`, because `AUTO` resolves to a sequence
 * on PostgreSQL and to an identity column elsewhere, and one migration cannot match both.
 */
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI transaction log, the stored cards, the received events and the gateway notices.';
    }

    public function up(Schema $schema): void
    {
        $this->createTransactionTable($schema);
        $this->createStoredCardTable($schema);
        $this->createReceivedEventTable($schema);
        $this->createGatewayNoticeTable($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('jpm_martin_sylius_nmi_gateway_notice');
        $schema->dropTable('jpm_martin_sylius_nmi_received_event');
        $schema->dropTable('jpm_martin_sylius_nmi_stored_card');
        $schema->dropTable('jpm_martin_sylius_nmi_transaction');
    }

    /** One row per gateway transaction; a capture or a void reuses the authorisation's id, so the id is unique per operation. */
    private function createTransactionTable(Schema $schema): void
    {
        $table = $schema->createTable('jpm_martin_sylius_nmi_transaction');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('payment_id', 'integer');
        $table->addColumn('transaction_id', 'string', ['length' => 64]);
        $table->addColumn('type', 'string', ['length' => 16]);
        $table->addColumn('parent_transaction_id', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('amount', 'integer');
        $table->addColumn('currency_code', 'string', ['length' => 3]);
        $table->addColumn('auth_code', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('settled_at', 'datetime_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        // A payment's transactions go with the payment: they have no meaning without it.
        $table->addForeignKeyConstraint('sylius_payment', ['payment_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addIndex(['transaction_id'], 'idx_jpm_martin_sylius_nmi_transaction_transaction_id');
        $table->addIndex(['parent_transaction_id'], 'idx_jpm_martin_sylius_nmi_transaction_parent');
        $table->addUniqueIndex(['transaction_id', 'type'], 'uniq_jpm_martin_sylius_nmi_transaction_id_type');
    }

    /**
     * The three identifier columns are text rather than sized strings because they hold ciphertext.
     * The payment method is restricted rather than cascaded: dropping a payment method that still has
     * stored cards would strand vault records at the gateway with nothing left pointing at them.
     */
    private function createStoredCardTable(Schema $schema): void
    {
        $table = $schema->createTable('jpm_martin_sylius_nmi_stored_card');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('customer_id', 'integer');
        $table->addColumn('payment_method_id', 'integer');
        $table->addColumn('vault_id', 'text');
        $table->addColumn('billing_id', 'text', ['notnull' => false]);
        $table->addColumn('vaulting_transaction_id', 'text', ['notnull' => false]);
        $table->addColumn('brand', 'string', ['length' => 32]);
        $table->addColumn('last_four', 'string', ['length' => 4]);
        $table->addColumn('expiry_month', 'smallint');
        $table->addColumn('expiry_year', 'smallint');
        $table->addColumn('status', 'string', ['length' => 16]);
        $table->addColumn('is_default', 'boolean');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('sylius_customer', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('sylius_payment_method', ['payment_method_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $table->addIndex(['customer_id', 'payment_method_id'], 'idx_jpm_martin_sylius_nmi_stored_card_owner');
    }

    /**
     * One row per accepted webhook delivery. The unique index on `event_id` is the point of the
     * table, not an optimisation: it is what makes a repeated delivery change state once, and it has
     * to be the database's constraint rather than a query, because two simultaneous deliveries both
     * pass a select-then-insert.
     */
    private function createReceivedEventTable(Schema $schema): void
    {
        $table = $schema->createTable('jpm_martin_sylius_nmi_received_event');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('event_id', 'string', ['length' => 64]);
        $table->addColumn('event_type', 'string', ['length' => 64]);
        $table->addColumn('payment_method_code', 'string', ['length' => 255]);
        $table->addColumn('payload', 'text');
        $table->addColumn('received_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);

        $table->addIndex(['received_at'], 'idx_jpm_martin_sylius_nmi_received_event_received_at');
        $table->addUniqueIndex(['event_id'], 'uniq_jpm_martin_sylius_nmi_received_event_id');
    }

    /**
     * Separate from the received-event table because that one is pruned and this one is not: a
     * chargeback matters for months, and the replay guard is measured in days.
     */
    private function createGatewayNoticeTable(Schema $schema): void
    {
        $table = $schema->createTable('jpm_martin_sylius_nmi_gateway_notice');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('payment_id', 'integer', ['notnull' => false]);
        $table->addColumn('type', 'string', ['length' => 32]);
        $table->addColumn('reference', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('payment_method_code', 'string', ['length' => 255]);
        $table->addColumn('amount', 'integer', ['notnull' => false]);
        $table->addColumn('currency_code', 'string', ['length' => 3, 'notnull' => false]);
        $table->addColumn('reason', 'text', ['notnull' => false]);
        $table->addColumn('occurred_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);

        // A notice outlives the payment it was about: the payment is unlinked, the notice stays.
        $table->addForeignKeyConstraint('sylius_payment', ['payment_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addIndex(['occurred_at'], 'idx_jpm_martin_sylius_nmi_notice_occurred_at');
        $table->addIndex(['type'], 'idx_jpm_martin_sylius_nmi_notice_type');
        $table->addUniqueIndex(['type', 'reference'], 'uniq_jpm_martin_sylius_nmi_notice_type_reference');
    }
}

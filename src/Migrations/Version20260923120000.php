<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the recurring credentials: cards kept at checkout on a promise of recurring charges.
 *
 * A migration of its own, as every addition is: one that has already run in a store is never changed.
 * Written on the schema object, for every engine, and held to the mapping by the same test.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI recurring credentials, kept at checkout to be charged again for new payments.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('jpm_martin_sylius_nmi_recurring_credential');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('initial_payment_id', 'integer', ['notnull' => false]);
        $table->addColumn('customer_id', 'integer');
        $table->addColumn('payment_method_id', 'integer');
        $table->addColumn('vault_id', 'text');
        $table->addColumn('billing_id', 'text', ['notnull' => false]);
        $table->addColumn('initial_transaction_id', 'text', ['notnull' => false]);
        $table->addColumn('brand', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('last_four', 'string', ['length' => 4, 'notnull' => false]);
        $table->addColumn('expiry_month', 'smallint', ['notnull' => false]);
        $table->addColumn('expiry_year', 'smallint', ['notnull' => false]);
        $table->addColumn('status', 'string', ['length' => 16]);
        $table->addColumn('released_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        // The database's constraint, not a query: one credential per opening payment, even for two
        // submissions of the pay page arriving together.
        $table->addUniqueIndex(['initial_payment_id'], 'uniq_jpm_martin_sylius_nmi_recurring_credential_payment');
        // Set to null: the promise outlives the order that opened it.
        $table->addForeignKeyConstraint('sylius_payment', ['initial_payment_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint('sylius_customer', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('sylius_payment_method', ['payment_method_id'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('jpm_martin_sylius_nmi_recurring_credential');
    }
}

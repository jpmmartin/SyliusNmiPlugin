<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the cards put on file at checkout to be charged later.
 *
 * A migration of its own rather than an edit of the first one, which has already run in every
 * store that installed a released version: a migration that has run is never changed. Written the
 * same way — on the schema object, for every engine — and held to the mapping by the same test.
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI cards on file, put on file at checkout to be charged later.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('jpm_martin_sylius_nmi_card_on_file');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('payment_id', 'integer');
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

        // The database's constraint, not a query: one card per payment, even for two submissions of
        // the pay page arriving together.
        $table->addUniqueIndex(['payment_id'], 'uniq_jpm_martin_sylius_nmi_card_on_file_payment');
        $table->addForeignKeyConstraint('sylius_payment', ['payment_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('sylius_payment_method', ['payment_method_id'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('jpm_martin_sylius_nmi_card_on_file');
    }
}

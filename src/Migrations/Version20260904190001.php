<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractPostgreSQLMigration;

/**
 * PostgreSQL half of the plugin's second schema change; Version20260904190000 is the MySQL half.
 */
final class Version20260904190001 extends AbstractPostgreSQLMigration
{
    public function getDescription(): string
    {
        return 'Creates the table of cards stored in the gateway vault (PostgreSQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE jpm_martin_sylius_nmi_stored_card_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE jpm_martin_sylius_nmi_stored_card (id INT NOT NULL, customer_id INT NOT NULL, payment_method_id INT NOT NULL, vault_id TEXT NOT NULL, billing_id TEXT DEFAULT NULL, vaulting_transaction_id TEXT DEFAULT NULL, brand VARCHAR(32) NOT NULL, last_four VARCHAR(4) NOT NULL, expiry_month SMALLINT NOT NULL, expiry_year SMALLINT NOT NULL, is_default BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7791EEE89395C3F3 ON jpm_martin_sylius_nmi_stored_card (customer_id)');
        $this->addSql('CREATE INDEX IDX_7791EEE85AA1164F ON jpm_martin_sylius_nmi_stored_card (payment_method_id)');
        $this->addSql('CREATE INDEX idx_jpm_martin_sylius_nmi_stored_card_owner ON jpm_martin_sylius_nmi_stored_card (customer_id, payment_method_id)');
        $this->addSql('COMMENT ON COLUMN jpm_martin_sylius_nmi_stored_card.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN jpm_martin_sylius_nmi_stored_card.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card ADD CONSTRAINT FK_7791EEE89395C3F3 FOREIGN KEY (customer_id) REFERENCES sylius_customer (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card ADD CONSTRAINT FK_7791EEE85AA1164F FOREIGN KEY (payment_method_id) REFERENCES sylius_payment_method (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card DROP CONSTRAINT FK_7791EEE89395C3F3');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card DROP CONSTRAINT FK_7791EEE85AA1164F');
        $this->addSql('DROP TABLE jpm_martin_sylius_nmi_stored_card');
        $this->addSql('DROP SEQUENCE jpm_martin_sylius_nmi_stored_card_id_seq CASCADE');
    }
}

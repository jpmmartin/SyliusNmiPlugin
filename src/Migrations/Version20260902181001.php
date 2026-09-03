<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractPostgreSQLMigration;

/**
 * PostgreSQL half of the plugin's first schema change; Version20260902181000 is the MySQL half.
 */
final class Version20260902181001 extends AbstractPostgreSQLMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI transaction log, one row per gateway transaction (PostgreSQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE jpm_martin_sylius_nmi_transaction_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE jpm_martin_sylius_nmi_transaction (id INT NOT NULL, payment_id INT NOT NULL, transaction_id VARCHAR(64) NOT NULL, type VARCHAR(16) NOT NULL, amount INT NOT NULL, currency_code VARCHAR(3) NOT NULL, auth_code VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, settled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_5C14A2864C3A3BB ON jpm_martin_sylius_nmi_transaction (payment_id)');
        $this->addSql('CREATE INDEX idx_jpm_martin_sylius_nmi_transaction_transaction_id ON jpm_martin_sylius_nmi_transaction (transaction_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jpm_martin_sylius_nmi_transaction_id_type ON jpm_martin_sylius_nmi_transaction (transaction_id, type)');
        $this->addSql('COMMENT ON COLUMN jpm_martin_sylius_nmi_transaction.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN jpm_martin_sylius_nmi_transaction.settled_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_transaction ADD CONSTRAINT FK_5C14A2864C3A3BB FOREIGN KEY (payment_id) REFERENCES sylius_payment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_transaction DROP CONSTRAINT FK_5C14A2864C3A3BB');
        $this->addSql('DROP TABLE jpm_martin_sylius_nmi_transaction');
        $this->addSql('DROP SEQUENCE jpm_martin_sylius_nmi_transaction_id_seq CASCADE');
    }
}

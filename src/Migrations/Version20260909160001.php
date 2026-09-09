<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractPostgreSQLMigration;

/**
 * PostgreSQL half of the operator-notice record; Version20260909160000 is the MySQL half.
 */
final class Version20260909160001 extends AbstractPostgreSQLMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI operator-notice record for chargebacks and failed settlements (PostgreSQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE jpm_martin_sylius_nmi_gateway_notice_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE jpm_martin_sylius_nmi_gateway_notice (id INT NOT NULL, payment_id INT DEFAULT NULL, type VARCHAR(32) NOT NULL, reference VARCHAR(64) DEFAULT NULL, payment_method_code VARCHAR(255) NOT NULL, amount INT DEFAULT NULL, currency_code VARCHAR(3) DEFAULT NULL, reason TEXT DEFAULT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_NMI_NOTICE_PAYMENT ON jpm_martin_sylius_nmi_gateway_notice (payment_id)');
        $this->addSql('CREATE INDEX idx_jpm_martin_sylius_nmi_notice_occurred_at ON jpm_martin_sylius_nmi_gateway_notice (occurred_at)');
        $this->addSql('CREATE INDEX idx_jpm_martin_sylius_nmi_notice_type ON jpm_martin_sylius_nmi_gateway_notice (type)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jpm_martin_sylius_nmi_notice_type_reference ON jpm_martin_sylius_nmi_gateway_notice (type, reference)');
        $this->addSql('COMMENT ON COLUMN jpm_martin_sylius_nmi_gateway_notice.occurred_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_gateway_notice ADD CONSTRAINT FK_NMI_NOTICE_PAYMENT FOREIGN KEY (payment_id) REFERENCES sylius_payment (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_gateway_notice DROP CONSTRAINT FK_NMI_NOTICE_PAYMENT');
        $this->addSql('DROP TABLE jpm_martin_sylius_nmi_gateway_notice');
        $this->addSql('DROP SEQUENCE jpm_martin_sylius_nmi_gateway_notice_id_seq CASCADE');
    }
}

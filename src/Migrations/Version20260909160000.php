<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

/**
 * MySQL half of the operator-notice record; Version20260909160001 is the PostgreSQL half.
 *
 * Separate from the received-event table because that one is pruned and this one is not: a
 * chargeback matters for months, and the replay guard is measured in days.
 */
final class Version20260909160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI operator-notice record for chargebacks and failed settlements (MySQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jpm_martin_sylius_nmi_gateway_notice (id INT AUTO_INCREMENT NOT NULL, payment_id INT DEFAULT NULL, type VARCHAR(32) NOT NULL, reference VARCHAR(64) DEFAULT NULL, payment_method_code VARCHAR(255) NOT NULL, amount INT DEFAULT NULL, currency_code VARCHAR(3) DEFAULT NULL, reason LONGTEXT DEFAULT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_NMI_NOTICE_PAYMENT (payment_id), INDEX idx_jpm_martin_sylius_nmi_notice_occurred_at (occurred_at), INDEX idx_jpm_martin_sylius_nmi_notice_type (type), UNIQUE INDEX uniq_jpm_martin_sylius_nmi_notice_type_reference (type, reference), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_gateway_notice ADD CONSTRAINT FK_NMI_NOTICE_PAYMENT FOREIGN KEY (payment_id) REFERENCES sylius_payment (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_gateway_notice DROP FOREIGN KEY FK_NMI_NOTICE_PAYMENT');
        $this->addSql('DROP TABLE jpm_martin_sylius_nmi_gateway_notice');
    }
}

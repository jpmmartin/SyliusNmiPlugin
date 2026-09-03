<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

/**
 * MySQL half of the plugin's first schema change; Version20260902181001 is the PostgreSQL half.
 * Each skips itself on the other platform, which is how Sylius itself ships every migration.
 */
final class Version20260902181000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI transaction log, one row per gateway transaction (MySQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jpm_martin_sylius_nmi_transaction (id INT AUTO_INCREMENT NOT NULL, payment_id INT NOT NULL, transaction_id VARCHAR(64) NOT NULL, type VARCHAR(16) NOT NULL, parent_transaction_id VARCHAR(64) DEFAULT NULL, amount INT NOT NULL, currency_code VARCHAR(3) NOT NULL, auth_code VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', settled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_5C14A2864C3A3BB (payment_id), INDEX idx_jpm_martin_sylius_nmi_transaction_transaction_id (transaction_id), INDEX idx_jpm_martin_sylius_nmi_transaction_parent (parent_transaction_id), UNIQUE INDEX uniq_jpm_martin_sylius_nmi_transaction_id_type (transaction_id, type), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_transaction ADD CONSTRAINT FK_5C14A2864C3A3BB FOREIGN KEY (payment_id) REFERENCES sylius_payment (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_transaction DROP FOREIGN KEY FK_5C14A2864C3A3BB');
        $this->addSql('DROP TABLE jpm_martin_sylius_nmi_transaction');
    }
}

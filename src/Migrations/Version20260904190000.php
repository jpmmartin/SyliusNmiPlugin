<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

/**
 * MySQL half of the plugin's second schema change; Version20260904190001 is the PostgreSQL half.
 *
 * The three identifier columns are text rather than sized strings because they hold ciphertext.
 * The payment method is restricted rather than cascaded: dropping a payment method that still has
 * stored cards would strand vault records at the gateway with nothing left pointing at them.
 */
final class Version20260904190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the table of cards stored in the gateway vault (MySQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jpm_martin_sylius_nmi_stored_card (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, payment_method_id INT NOT NULL, vault_id LONGTEXT NOT NULL, billing_id LONGTEXT DEFAULT NULL, vaulting_transaction_id LONGTEXT DEFAULT NULL, brand VARCHAR(32) NOT NULL, last_four VARCHAR(4) NOT NULL, expiry_month SMALLINT NOT NULL, expiry_year SMALLINT NOT NULL, is_default TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_7791EEE89395C3F3 (customer_id), INDEX IDX_7791EEE85AA1164F (payment_method_id), INDEX idx_jpm_martin_sylius_nmi_stored_card_owner (customer_id, payment_method_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card ADD CONSTRAINT FK_7791EEE89395C3F3 FOREIGN KEY (customer_id) REFERENCES sylius_customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card ADD CONSTRAINT FK_7791EEE85AA1164F FOREIGN KEY (payment_method_id) REFERENCES sylius_payment_method (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card DROP FOREIGN KEY FK_7791EEE89395C3F3');
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card DROP FOREIGN KEY FK_7791EEE85AA1164F');
        $this->addSql('DROP TABLE jpm_martin_sylius_nmi_stored_card');
    }
}

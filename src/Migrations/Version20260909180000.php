<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

/**
 * MySQL half of the stored-card status; Version20260909180001 is the PostgreSQL half.
 *
 * Existing rows become active, which is what they were: nothing had told this store otherwise.
 */
final class Version20260909180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the status an NMI stored card carries once the issuer has reported on it (MySQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE jpm_martin_sylius_nmi_stored_card ADD status VARCHAR(16) DEFAULT 'active' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jpm_martin_sylius_nmi_stored_card DROP status');
    }
}

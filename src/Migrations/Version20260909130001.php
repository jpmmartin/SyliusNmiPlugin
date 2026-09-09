<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractPostgreSQLMigration;

/**
 * PostgreSQL half of the received-event table; Version20260909130000 is the MySQL half.
 */
final class Version20260909130001 extends AbstractPostgreSQLMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI received-event record, one row per accepted webhook delivery (PostgreSQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE jpm_martin_sylius_nmi_received_event_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE jpm_martin_sylius_nmi_received_event (id INT NOT NULL, event_id VARCHAR(64) NOT NULL, event_type VARCHAR(64) NOT NULL, payment_method_code VARCHAR(255) NOT NULL, payload TEXT NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_jpm_martin_sylius_nmi_received_event_received_at ON jpm_martin_sylius_nmi_received_event (received_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jpm_martin_sylius_nmi_received_event_id ON jpm_martin_sylius_nmi_received_event (event_id)');
        $this->addSql('COMMENT ON COLUMN jpm_martin_sylius_nmi_received_event.received_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE jpm_martin_sylius_nmi_received_event');
        $this->addSql('DROP SEQUENCE jpm_martin_sylius_nmi_received_event_id_seq CASCADE');
    }
}

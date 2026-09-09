<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

/**
 * MySQL half of the received-event table; Version20260909130001 is the PostgreSQL half. Each skips
 * itself on the other platform, which is what lets one package ship both.
 *
 * The unique index on `event_id` is the point of the table, not an optimisation: it is what makes a
 * repeated delivery change state once, and it has to be the database's constraint rather than a
 * query, because two simultaneous deliveries both pass a select-then-insert.
 */
final class Version20260909130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the NMI received-event record, one row per accepted webhook delivery (MySQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jpm_martin_sylius_nmi_received_event (id INT AUTO_INCREMENT NOT NULL, event_id VARCHAR(64) NOT NULL, event_type VARCHAR(64) NOT NULL, payment_method_code VARCHAR(255) NOT NULL, payload LONGTEXT NOT NULL, received_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_jpm_martin_sylius_nmi_received_event_received_at (received_at), UNIQUE INDEX uniq_jpm_martin_sylius_nmi_received_event_id (event_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE jpm_martin_sylius_nmi_received_event');
    }
}

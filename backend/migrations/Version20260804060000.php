<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Local cache of every Scryfall-legal commander (is:commander).
 *
 * Commander search must not depend on store inventory or a partial cards
 * sync — this table is refreshed weekly from Scryfall so typeahead stays
 * local and API traffic stays offline for day-to-day browsing.
 */
final class Version20260804060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add commanders catalog table for weekly Scryfall is:commander sync';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commanders (oracle_id UUID NOT NULL, card_id UUID NOT NULL, name VARCHAR(255) NOT NULL, type_line VARCHAR(255) DEFAULT NULL, mana_cost VARCHAR(64) DEFAULT NULL, cmc DOUBLE PRECISION DEFAULT NULL, color_identity JSON DEFAULT NULL, image_uri VARCHAR(512) DEFAULT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (oracle_id))');
        $this->addSql('CREATE INDEX idx_commander_name_lower ON commanders (LOWER(name))');
        $this->addSql('CREATE INDEX idx_commander_name ON commanders (name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_commander_card ON commanders (card_id)');
        $this->addSql('ALTER TABLE commanders ADD CONSTRAINT fk_commander_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commanders DROP CONSTRAINT fk_commander_card');
        $this->addSql('DROP TABLE commanders');
    }
}

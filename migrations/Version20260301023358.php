<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260301023358 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sync_run_contact (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL, sync_run_id BINARY(16) NOT NULL, INDEX IDX_AF2C0FCD2C62101B (sync_run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sync_run_contact ADD CONSTRAINT FK_AF2C0FCD2C62101B FOREIGN KEY (sync_run_id) REFERENCES sync_run (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sync_run_contact DROP FOREIGN KEY FK_AF2C0FCD2C62101B');
        $this->addSql('DROP TABLE sync_run_contact');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260226020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy credential columns from organization table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP COLUMN planning_center_app_id');
        $this->addSql('ALTER TABLE organization DROP COLUMN planning_center_app_secret');
        $this->addSql('ALTER TABLE organization DROP COLUMN google_o_auth_credentials');
        $this->addSql('ALTER TABLE organization DROP COLUMN google_domain');
        $this->addSql('ALTER TABLE organization DROP COLUMN google_token');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE organization ADD planning_center_app_id TEXT NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE organization ADD planning_center_app_secret TEXT NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE organization ADD google_o_auth_credentials TEXT NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE organization ADD google_domain VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE organization ADD google_token TEXT DEFAULT NULL');
    }
}

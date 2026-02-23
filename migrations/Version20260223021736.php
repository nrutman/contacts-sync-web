<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260223021736 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial schema: user, organization, sync_list, in_memory_contact, sync_run tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE in_memory_contact (id UUID NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE INDEX IDX_B6F01FCD32C8A3DE ON in_memory_contact (organization_id)',
        );
        $this->addSql(
            'CREATE TABLE in_memory_contact_sync_list (in_memory_contact_id UUID NOT NULL, sync_list_id UUID NOT NULL, PRIMARY KEY (in_memory_contact_id, sync_list_id))',
        );
        $this->addSql(
            'CREATE INDEX IDX_1974932B8FD13C17 ON in_memory_contact_sync_list (in_memory_contact_id)',
        );
        $this->addSql(
            'CREATE INDEX IDX_1974932B2B6A5820 ON in_memory_contact_sync_list (sync_list_id)',
        );
        $this->addSql(
            'CREATE TABLE organization (id UUID NOT NULL, name VARCHAR(255) NOT NULL, planning_center_app_id TEXT NOT NULL, planning_center_app_secret TEXT NOT NULL, google_oauth_credentials TEXT NOT NULL, google_domain VARCHAR(255) NOT NULL, google_token TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE TABLE sync_list (id UUID NOT NULL, name VARCHAR(255) NOT NULL, is_enabled BOOLEAN NOT NULL, cron_expression VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE INDEX IDX_161E545732C8A3DE ON sync_list (organization_id)',
        );
        $this->addSql(
            'CREATE TABLE sync_run (id UUID NOT NULL, triggered_by VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, source_count INT DEFAULT NULL, destination_count INT DEFAULT NULL, added_count INT DEFAULT NULL, removed_count INT DEFAULT NULL, log TEXT DEFAULT NULL, error_message TEXT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sync_list_id UUID NOT NULL, triggered_by_user_id UUID DEFAULT NULL, PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE INDEX IDX_EE38DD732B6A5820 ON sync_run (sync_list_id)',
        );
        $this->addSql(
            'CREATE INDEX IDX_EE38DD739CE7D53B ON sync_run (triggered_by_user_id)',
        );
        $this->addSql(
            'CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) DEFAULT NULL, is_verified BOOLEAN NOT NULL, roles JSON NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, notify_on_success BOOLEAN NOT NULL, notify_on_failure BOOLEAN NOT NULL, notify_on_no_changes BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)',
        );
        $this->addSql(
            'ALTER TABLE in_memory_contact ADD CONSTRAINT FK_B6F01FCD32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE',
        );
        $this->addSql(
            'ALTER TABLE in_memory_contact_sync_list ADD CONSTRAINT FK_1974932B8FD13C17 FOREIGN KEY (in_memory_contact_id) REFERENCES in_memory_contact (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE in_memory_contact_sync_list ADD CONSTRAINT FK_1974932B2B6A5820 FOREIGN KEY (sync_list_id) REFERENCES sync_list (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE sync_list ADD CONSTRAINT FK_161E545732C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE',
        );
        $this->addSql(
            'ALTER TABLE sync_run ADD CONSTRAINT FK_EE38DD732B6A5820 FOREIGN KEY (sync_list_id) REFERENCES sync_list (id) NOT DEFERRABLE',
        );
        $this->addSql(
            'ALTER TABLE sync_run ADD CONSTRAINT FK_EE38DD739CE7D53B FOREIGN KEY (triggered_by_user_id) REFERENCES "user" (id) NOT DEFERRABLE',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'ALTER TABLE in_memory_contact DROP CONSTRAINT FK_B6F01FCD32C8A3DE',
        );
        $this->addSql(
            'ALTER TABLE in_memory_contact_sync_list DROP CONSTRAINT FK_1974932B8FD13C17',
        );
        $this->addSql(
            'ALTER TABLE in_memory_contact_sync_list DROP CONSTRAINT FK_1974932B2B6A5820',
        );
        $this->addSql(
            'ALTER TABLE sync_list DROP CONSTRAINT FK_161E545732C8A3DE',
        );
        $this->addSql(
            'ALTER TABLE sync_run DROP CONSTRAINT FK_EE38DD732B6A5820',
        );
        $this->addSql(
            'ALTER TABLE sync_run DROP CONSTRAINT FK_EE38DD739CE7D53B',
        );
        $this->addSql('DROP TABLE in_memory_contact');
        $this->addSql('DROP TABLE in_memory_contact_sync_list');
        $this->addSql('DROP TABLE organization');
        $this->addSql('DROP TABLE sync_list');
        $this->addSql('DROP TABLE sync_run');
        $this->addSql('DROP TABLE "user"');
    }
}

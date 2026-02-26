<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260226010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create provider_credential table and add source/destination fields to sync_list';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE provider_credential (id UUID NOT NULL, organization_id UUID NOT NULL, provider_name VARCHAR(50) NOT NULL, label VARCHAR(255) DEFAULT NULL, credentials TEXT NOT NULL, metadata JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE INDEX IDX_provider_credential_org ON provider_credential (organization_id)',
        );
        $this->addSql(
            'ALTER TABLE provider_credential ADD CONSTRAINT FK_provider_credential_org FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE',
        );

        // Add source/destination credential references and list identifiers to sync_list
        $this->addSql('ALTER TABLE sync_list ADD source_credential_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE sync_list ADD source_list_identifier VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE sync_list ADD destination_credential_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE sync_list ADD destination_list_identifier VARCHAR(255) DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE sync_list ADD CONSTRAINT FK_sync_list_source_cred FOREIGN KEY (source_credential_id) REFERENCES provider_credential (id) NOT DEFERRABLE',
        );
        $this->addSql(
            'ALTER TABLE sync_list ADD CONSTRAINT FK_sync_list_dest_cred FOREIGN KEY (destination_credential_id) REFERENCES provider_credential (id) NOT DEFERRABLE',
        );
        $this->addSql('CREATE INDEX IDX_sync_list_source_cred ON sync_list (source_credential_id)');
        $this->addSql('CREATE INDEX IDX_sync_list_dest_cred ON sync_list (destination_credential_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_list DROP CONSTRAINT FK_sync_list_source_cred');
        $this->addSql('ALTER TABLE sync_list DROP CONSTRAINT FK_sync_list_dest_cred');
        $this->addSql('DROP INDEX IDX_sync_list_source_cred');
        $this->addSql('DROP INDEX IDX_sync_list_dest_cred');
        $this->addSql('ALTER TABLE sync_list DROP COLUMN source_credential_id');
        $this->addSql('ALTER TABLE sync_list DROP COLUMN source_list_identifier');
        $this->addSql('ALTER TABLE sync_list DROP COLUMN destination_credential_id');
        $this->addSql('ALTER TABLE sync_list DROP COLUMN destination_list_identifier');
        $this->addSql('ALTER TABLE provider_credential DROP CONSTRAINT FK_provider_credential_org');
        $this->addSql('DROP TABLE provider_credential');
    }
}

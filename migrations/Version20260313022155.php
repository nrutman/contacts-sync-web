<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260313022155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate UUID columns from BINARY(16) to CHAR(36) with RFC 4122 string storage';
    }

    public function up(Schema $schema): void
    {
        // FKs were already dropped in a previous partial run.
        // If running fresh, uncomment the FK drops below.
        // $this->addSql('ALTER TABLE manual_contact DROP FOREIGN KEY FK_AE95011B32C8A3DE');
        // ... etc.

        // For each table: widen to VARBINARY(36) first (preserves binary bytes),
        // then convert data to RFC 4122 string, then finalize as CHAR(36).

        $this->convertColumn('organization', 'id', false);
        $this->convertColumn('`user`', 'id', false);

        $this->convertColumns('provider_credential', [
            'id' => false,
            'organization_id' => false,
        ]);

        $this->convertColumns('manual_contact', [
            'id' => false,
            'organization_id' => false,
        ]);

        $this->convertColumns('sync_list', [
            'id' => false,
            'organization_id' => false,
            'source_credential_id' => true,
            'destination_credential_id' => true,
        ]);

        $this->convertColumns('sync_run', [
            'id' => false,
            'sync_list_id' => false,
            'triggered_by_user_id' => true,
        ]);

        $this->convertColumns('sync_run_contact', [
            'id' => false,
            'sync_run_id' => false,
        ]);

        $this->convertColumns('manual_contact_sync_list', [
            'manual_contact_id' => false,
            'sync_list_id' => false,
        ]);

        // Re-add all foreign keys
        $this->addSql('ALTER TABLE manual_contact ADD CONSTRAINT FK_AE95011B32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('ALTER TABLE manual_contact_sync_list ADD CONSTRAINT FK_E34389B02B6A5820 FOREIGN KEY (sync_list_id) REFERENCES sync_list (id)');
        $this->addSql('ALTER TABLE manual_contact_sync_list ADD CONSTRAINT FK_E34389B0EF4CDF85 FOREIGN KEY (manual_contact_id) REFERENCES manual_contact (id)');
        $this->addSql('ALTER TABLE provider_credential ADD CONSTRAINT FK_20C951F332C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('ALTER TABLE sync_list ADD CONSTRAINT FK_161E54572E32565F FOREIGN KEY (destination_credential_id) REFERENCES provider_credential (id)');
        $this->addSql('ALTER TABLE sync_list ADD CONSTRAINT FK_161E545732C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('ALTER TABLE sync_list ADD CONSTRAINT FK_161E5457E03476C7 FOREIGN KEY (source_credential_id) REFERENCES provider_credential (id)');
        $this->addSql('ALTER TABLE sync_run ADD CONSTRAINT FK_EE38DD732B6A5820 FOREIGN KEY (sync_list_id) REFERENCES sync_list (id)');
        $this->addSql('ALTER TABLE sync_run ADD CONSTRAINT FK_EE38DD739CE7D53B FOREIGN KEY (triggered_by_user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE sync_run_contact ADD CONSTRAINT FK_AF2C0FCD2C62101B FOREIGN KEY (sync_run_id) REFERENCES sync_run (id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Cannot convert CHAR(36) UUIDs back to BINARY(16).');
    }

    /**
     * Converts a single column from BINARY(16) to CHAR(36) via VARBINARY(36) intermediate.
     */
    private function convertColumn(string $table, string $column, bool $nullable): void
    {
        $nullability = $nullable ? 'DEFAULT NULL' : 'NOT NULL';

        // Step 1: Widen to VARBINARY(36) — preserves binary data, allows 36 bytes
        $this->addSql("ALTER TABLE {$table} MODIFY {$column} VARBINARY(36) {$nullability}");

        // Step 2: Convert binary data to RFC 4122 string format
        $hexExpr = "LOWER(CONCAT(LEFT(HEX({$column}),8),'-',SUBSTR(HEX({$column}),9,4),'-',SUBSTR(HEX({$column}),13,4),'-',SUBSTR(HEX({$column}),17,4),'-',RIGHT(HEX({$column}),12)))";
        if ($nullable) {
            $this->addSql("UPDATE {$table} SET {$column} = {$hexExpr} WHERE {$column} IS NOT NULL");
        } else {
            $this->addSql("UPDATE {$table} SET {$column} = {$hexExpr}");
        }

        // Step 3: Finalize as CHAR(36)
        $this->addSql("ALTER TABLE {$table} MODIFY {$column} CHAR(36) {$nullability}");
    }

    /**
     * Converts multiple columns on the same table.
     *
     * @param array<string, bool> $columns column name => nullable
     */
    private function convertColumns(string $table, array $columns): void
    {
        foreach ($columns as $column => $nullable) {
            $this->convertColumn($table, $column, $nullable);
        }
    }
}

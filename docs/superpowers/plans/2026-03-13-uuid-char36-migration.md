# UUID CHAR(36) Migration Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Switch all UUID columns from `BINARY(16)` to `CHAR(36)` string storage on MySQL, eliminating binary conversion bugs while preserving `Uuid` object hydration.

**Architecture:** A custom `StringUuidType` extends Symfony's `UuidType`, overriding `getSQLDeclaration()` and `convertToDatabaseValue()` to force RFC 4122 string storage. A hand-written Doctrine migration converts existing data. Repository code is simplified by removing all binary-related workarounds.

**Tech Stack:** PHP 8.5, Symfony 8.0, Doctrine ORM/DBAL, MySQL 8, Symfony UidComponent

**Spec:** `docs/superpowers/specs/2026-03-13-uuid-char36-migration-design.md`

---

## Chunk 1: Custom Type + Tests

### Task 1: Create StringUuidType with tests (TDD)

**Files:**
- Create: `tests/Doctrine/Type/StringUuidTypeTest.php`
- Create: `src/Doctrine/Type/StringUuidType.php`
- Modify: `config/packages/doctrine.yaml:1-3`

- [ ] **Step 1: Write failing tests**

Create `tests/Doctrine/Type/StringUuidTypeTest.php`:

```php
<?php

namespace App\Tests\Doctrine\Type;

use App\Doctrine\Type\StringUuidType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Symfony\Component\Uid\Uuid;

class StringUuidTypeTest extends MockeryTestCase
{
    private StringUuidType $type;

    protected function setUp(): void
    {
        $this->type = new StringUuidType();
    }

    public function testNameReturnsUuidString(): void
    {
        $this->assertSame('uuid_string', $this->type->getName());
        $this->assertSame('uuid_string', StringUuidType::NAME);
    }

    public function testGetSqlDeclarationReturnCharOnMysql(): void
    {
        $platform = new MySQLPlatform();
        $declaration = $this->type->getSQLDeclaration([], $platform);

        $this->assertSame('CHAR(36)', $declaration);
    }

    public function testGetSqlDeclarationReturnUuidOnPostgres(): void
    {
        $platform = new PostgreSQLPlatform();
        $declaration = $this->type->getSQLDeclaration([], $platform);

        $this->assertSame('UUID', $declaration);
    }

    public function testConvertToDatabaseValueWithUuidObject(): void
    {
        $platform = new MySQLPlatform();
        $uuid = Uuid::v7();

        $result = $this->type->convertToDatabaseValue($uuid, $platform);

        $this->assertSame($uuid->toRfc4122(), $result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result,
        );
    }

    public function testConvertToDatabaseValueWithStringUuid(): void
    {
        $platform = new MySQLPlatform();
        $uuidString = '019c9bf7-6cbc-74fe-b1ba-7a7b35019bb9';

        $result = $this->type->convertToDatabaseValue($uuidString, $platform);

        $this->assertSame($uuidString, $result);
    }

    public function testConvertToDatabaseValueWithNull(): void
    {
        $platform = new MySQLPlatform();

        $this->assertNull($this->type->convertToDatabaseValue(null, $platform));
    }

    public function testConvertToDatabaseValueWithEmptyString(): void
    {
        $platform = new MySQLPlatform();

        $this->assertNull($this->type->convertToDatabaseValue('', $platform));
    }

    public function testConvertToPHPValueReturnsUuidObject(): void
    {
        $platform = new MySQLPlatform();
        $uuidString = '019c9bf7-6cbc-74fe-b1ba-7a7b35019bb9';

        $result = $this->type->convertToPHPValue($uuidString, $platform);

        $this->assertInstanceOf(Uuid::class, $result);
        $this->assertSame($uuidString, $result->toRfc4122());
    }

    public function testConvertToPHPValueWithNull(): void
    {
        $platform = new MySQLPlatform();

        $this->assertNull($this->type->convertToPHPValue(null, $platform));
    }

    public function testConvertToPHPValuePassesThroughUuidObject(): void
    {
        $platform = new MySQLPlatform();
        $uuid = Uuid::v7();

        $result = $this->type->convertToPHPValue($uuid, $platform);

        $this->assertSame($uuid, $result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer run-script test -- --filter=StringUuidTypeTest`
Expected: FAIL — class `StringUuidType` not found

- [ ] **Step 3: Create the StringUuidType class**

Create `src/Doctrine/Type/StringUuidType.php`:

```php
<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Uuid;

final class StringUuidType extends UuidType
{
    public const NAME = 'uuid_string';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof AbstractUid) {
            return $value->toRfc4122();
        }

        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Expected string, Uuid, or null; got %s.', get_debug_type($value)));
        }

        return Uuid::fromString($value)->toRfc4122();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer run-script test -- --filter=StringUuidTypeTest`
Expected: All 9 tests PASS

- [ ] **Step 5: Register the type in Doctrine config**

Edit `config/packages/doctrine.yaml`. Insert the `types` key under the existing `dbal` block, after the `url` line (line 3). Do NOT replace or remove any existing content — just insert these two lines:

```yaml
        types:
            uuid_string: App\Doctrine\Type\StringUuidType
```

The result should look like:

```yaml
doctrine:
    dbal:
        url: "%env(resolve:DATABASE_URL)%"
        types:
            uuid_string: App\Doctrine\Type\StringUuidType

        # IMPORTANT: You MUST configure your server version,
        # ... (rest of file unchanged)
```

- [ ] **Step 6: Run full test suite and CS check**

Run: `composer run-script test && composer run-script cs`
Expected: All tests pass, no CS violations. Fix any violations with `composer run-script cs-fix`.

- [ ] **Step 7: Commit**

```bash
git add src/Doctrine/Type/StringUuidType.php tests/Doctrine/Type/StringUuidTypeTest.php config/packages/doctrine.yaml
git commit -m "feat: add StringUuidType for RFC 4122 string UUID storage"
```

---

## Chunk 2: Entity Changes

### Task 2: Update all entity column types

**Files:**
- Modify: `src/Entity/Organization.php:9,18`
- Modify: `src/Entity/User.php:7,19`
- Modify: `src/Entity/SyncList.php:9,18`
- Modify: `src/Entity/SyncRun.php:9,17`
- Modify: `src/Entity/SyncRunContact.php:8,15`
- Modify: `src/Entity/ProviderCredential.php:8,17`
- Modify: `src/Entity/ManualContact.php:10,18`

In each entity, make two changes:

1. Replace the import: `use Symfony\Bridge\Doctrine\Types\UuidType;` → `use App\Doctrine\Type\StringUuidType;`
2. Replace the column attribute: `#[ORM\Column(type: UuidType::NAME, unique: true)]` → `#[ORM\Column(type: StringUuidType::NAME, unique: true)]`

- [ ] **Step 1: Update Organization entity**

In `src/Entity/Organization.php`:
- Line 9: `use Symfony\Bridge\Doctrine\Types\UuidType;` → `use App\Doctrine\Type\StringUuidType;`
- Line 18: `#[ORM\Column(type: UuidType::NAME, unique: true)]` → `#[ORM\Column(type: StringUuidType::NAME, unique: true)]`

- [ ] **Step 2: Update User entity**

In `src/Entity/User.php`:
- Line 7: `use Symfony\Bridge\Doctrine\Types\UuidType;` → `use App\Doctrine\Type\StringUuidType;`
- Line 19: `#[ORM\Column(type: UuidType::NAME, unique: true)]` → `#[ORM\Column(type: StringUuidType::NAME, unique: true)]`

- [ ] **Step 3: Update SyncList entity**

In `src/Entity/SyncList.php`:
- Line 9: `use Symfony\Bridge\Doctrine\Types\UuidType;` → `use App\Doctrine\Type\StringUuidType;`
- Line 18: `#[ORM\Column(type: UuidType::NAME, unique: true)]` → `#[ORM\Column(type: StringUuidType::NAME, unique: true)]`

- [ ] **Step 4: Update SyncRun entity**

In `src/Entity/SyncRun.php`:
- Line 9: `use Symfony\Bridge\Doctrine\Types\UuidType;` → `use App\Doctrine\Type\StringUuidType;`
- Line 17: `#[ORM\Column(type: UuidType::NAME, unique: true)]` → `#[ORM\Column(type: StringUuidType::NAME, unique: true)]`

- [ ] **Step 5: Update SyncRunContact entity**

In `src/Entity/SyncRunContact.php`:
- Line 8: `use Symfony\Bridge\Doctrine\Types\UuidType;` → `use App\Doctrine\Type\StringUuidType;`
- Line 15: `#[ORM\Column(type: UuidType::NAME, unique: true)]` → `#[ORM\Column(type: StringUuidType::NAME, unique: true)]`

- [ ] **Step 6: Update ProviderCredential entity**

In `src/Entity/ProviderCredential.php`:
- Line 8: `use Symfony\Bridge\Doctrine\Types\UuidType;` → `use App\Doctrine\Type\StringUuidType;`
- Line 17: `#[ORM\Column(type: UuidType::NAME, unique: true)]` → `#[ORM\Column(type: StringUuidType::NAME, unique: true)]`

- [ ] **Step 7: Update ManualContact entity**

In `src/Entity/ManualContact.php`:
- Line 10: `use Symfony\Bridge\Doctrine\Types\UuidType;` → `use App\Doctrine\Type\StringUuidType;`
- Line 18: `#[ORM\Column(type: UuidType::NAME, unique: true)]` → `#[ORM\Column(type: StringUuidType::NAME, unique: true)]`

- [ ] **Step 8: Check for any existing tests referencing UuidType::NAME**

Run: `grep -r "UuidType" tests/`
If any matches are found, update those references to `StringUuidType::NAME` and add the appropriate import.

- [ ] **Step 9: Run full test suite and CS check**

Run: `composer run-script test && composer run-script cs`
Expected: All tests pass. Fix any CS violations with `composer run-script cs-fix`.

- [ ] **Step 10: Commit**

```bash
git add src/Entity/
git commit -m "feat: switch entities from UuidType to StringUuidType"
```

---

## Chunk 3: Repository Simplifications

### Task 3: Simplify SyncListRepository

**Files:**
- Modify: `src/Repository/SyncListRepository.php`

- [ ] **Step 1: Remove UuidType::NAME from setParameter calls**

Remove `, UuidType::NAME` third argument from all 5 `setParameter()` calls:
- Line 32: `->setParameter('org', $organization->getId(), UuidType::NAME)` → `->setParameter('org', $organization->getId())`
- Line 48: same change
- Line 73: same change
- Line 87: same change
- Line 101: same change

- [ ] **Step 2: Simplify findByOrganizationAndIds and remove all unused imports**

Replace the `$uuids` conversion and typed parameter (lines 68-74):

Before:
```php
$uuids = array_map(static fn (string $id) => Uuid::fromString($id)->toBinary(), $ids);

return $this->createQueryBuilder('sl')
    ->where('sl.organization = :org')
    ->andWhere('sl.id IN (:ids)')
    ->setParameter('org', $organization->getId(), UuidType::NAME)
    ->setParameter('ids', $uuids, ArrayParameterType::BINARY)
```

After:
```php
return $this->createQueryBuilder('sl')
    ->where('sl.organization = :org')
    ->andWhere('sl.id IN (:ids)')
    ->setParameter('org', $organization->getId())
    ->setParameter('ids', $ids)
```

Then remove all three now-unused import lines:
- `use Doctrine\DBAL\ArrayParameterType;`
- `use Symfony\Bridge\Doctrine\Types\UuidType;`
- `use Symfony\Component\Uid\Uuid;`

- [ ] **Step 4: Run tests and CS check**

Run: `composer run-script test && composer run-script cs`
Expected: All pass. Fix CS violations with `composer run-script cs-fix`.

- [ ] **Step 5: Commit**

```bash
git add src/Repository/SyncListRepository.php
git commit -m "refactor: simplify SyncListRepository UUID handling"
```

### Task 4: Simplify SyncRunRepository

**Files:**
- Modify: `src/Repository/SyncRunRepository.php`

- [ ] **Step 1: Remove unused imports**

Remove these two import lines:
- `use Symfony\Bridge\Doctrine\Types\UuidType;`
- `use Symfony\Component\Uid\Uuid;`

- [ ] **Step 2: Remove UuidType::NAME from all 8 QueryBuilder setParameter calls**

Remove `, UuidType::NAME` from these lines:
- Line 33: `findRecentByOrganization` — org
- Line 49: `findLastCompletedByOrganization` — org
- Line 66: `findBySyncList` — syncList
- Line 90: `findByOrganizationPaginated` — org
- Line 94: `findByOrganizationPaginated` — syncList
- Line 116: `findLastBySyncList` — syncList
- Line 292: `countByOrganization` — org
- Line 296: `countByOrganization` — syncList

- [ ] **Step 3: Simplify findDestinationCountsByOrganization DBAL call**

Lines 147-151 — remove the type array entirely:

Before:
```php
$results = $conn->fetchAllAssociative($sql, [
    'org' => $organization->getId(),
], [
    'org' => UuidType::NAME,
]);
```

After:
```php
$results = $conn->fetchAllAssociative($sql, [
    'org' => $organization->getId(),
]);
```

- [ ] **Step 4: Replace normalizeUuid call in findDestinationCountsByOrganization**

Line 155:

Before: `$counts[self::normalizeUuid($row['list_id'])] = (int) $row['destination_count'];`
After: `$counts[$row['list_id']] = (int) $row['destination_count'];`

- [ ] **Step 5: Simplify findSourceCountsByOrganization DBAL call**

Lines 188-192 — remove the type array entirely (same pattern as step 3):

Before:
```php
$results = $conn->fetchAllAssociative($sql, [
    'org' => $organization->getId(),
], [
    'org' => UuidType::NAME,
]);
```

After:
```php
$results = $conn->fetchAllAssociative($sql, [
    'org' => $organization->getId(),
]);
```

- [ ] **Step 6: Replace normalizeUuid call in findSourceCountsByOrganization**

Line 196:

Before: `$counts[self::normalizeUuid($row['list_id'])] = (int) $row['source_count'];`
After: `$counts[$row['list_id']] = (int) $row['source_count'];`

- [ ] **Step 7: Simplify deleteOlderThan DBAL calls (keep cutoff type)**

First `executeStatement` call (lines 253-259):

Before:
```php
$conn->executeStatement($deleteContactsSql, [
    'org' => $organization->getId(),
    'cutoff' => $cutoff,
], [
    'org' => UuidType::NAME,
    'cutoff' => 'datetime_immutable',
]);
```

After:
```php
$conn->executeStatement($deleteContactsSql, [
    'org' => $organization->getId(),
    'cutoff' => $cutoff,
], [
    'cutoff' => 'datetime_immutable',
]);
```

Second `executeStatement` call (lines 271-277) — same change:

Before:
```php
return $conn->executeStatement($deleteRunsSql, [
    'org' => $organization->getId(),
    'cutoff' => $cutoff,
], [
    'org' => UuidType::NAME,
    'cutoff' => 'datetime_immutable',
]);
```

After:
```php
return $conn->executeStatement($deleteRunsSql, [
    'org' => $organization->getId(),
    'cutoff' => $cutoff,
], [
    'cutoff' => 'datetime_immutable',
]);
```

- [ ] **Step 8: Delete the normalizeUuid method**

Delete the entire method (lines 209-216):

```php
private static function normalizeUuid(string $value): string
{
    if (strlen($value) === 16) {
        return (string) Uuid::fromBinary($value);
    }

    return $value;
}
```

Also delete its docblock (lines 202-208).

- [ ] **Step 9: Run tests and CS check**

Run: `composer run-script test && composer run-script cs`
Expected: All pass. Fix CS violations with `composer run-script cs-fix`.

- [ ] **Step 10: Commit**

```bash
git add src/Repository/SyncRunRepository.php
git commit -m "refactor: simplify SyncRunRepository UUID handling, remove normalizeUuid"
```

### Task 5: Simplify SyncRunContactRepository and ManualContactRepository

**Files:**
- Modify: `src/Repository/SyncRunContactRepository.php`
- Modify: `src/Repository/ManualContactRepository.php`

- [ ] **Step 1: Simplify SyncRunContactRepository**

In `src/Repository/SyncRunContactRepository.php`:
- Remove import: `use Symfony\Bridge\Doctrine\Types\UuidType;` (line 9)
- Line 37: remove `, UuidType::NAME` from `->setParameter('syncList', $syncList->getId(), UuidType::NAME)`
- Line 47: remove `, UuidType::NAME` from `->setParameter('runId', $latestRunId['id'], UuidType::NAME)`

- [ ] **Step 2: Simplify ManualContactRepository**

In `src/Repository/ManualContactRepository.php`:
- Remove import: `use Symfony\Bridge\Doctrine\Types\UuidType;` (line 9)
- Line 31: remove `, UuidType::NAME` from `->setParameter('syncList', $syncList->getId(), UuidType::NAME)`

- [ ] **Step 3: Run tests and CS check**

Run: `composer run-script test && composer run-script cs`
Expected: All pass. Fix CS violations with `composer run-script cs-fix`.

- [ ] **Step 4: Commit**

```bash
git add src/Repository/SyncRunContactRepository.php src/Repository/ManualContactRepository.php
git commit -m "refactor: simplify remaining repositories UUID handling"
```

---

## Chunk 4: Database Migration

### Task 6: Write and run the database migration

**Files:**
- Create: `migrations/VersionXXXXXXXXXXXXXX.php` (use `doctrine:migrations:generate` for timestamp)

- [ ] **Step 1: Generate an empty migration file**

Run: `php bin/console doctrine:migrations:generate`

This creates a new file in `migrations/` with a timestamped class name.

- [ ] **Step 2: Write the migration**

Replace the generated `up()` and `down()` methods with the following. The migration:
1. Drops all 10 foreign keys
2. Converts data and alters columns in dependency order
3. Re-adds all 10 foreign keys

The SQL conversion expression for each column is:
```sql
UPDATE table SET col = LOWER(CONCAT(
    LEFT(HEX(col),8),'-',
    SUBSTR(HEX(col),9,4),'-',
    SUBSTR(HEX(col),13,4),'-',
    SUBSTR(HEX(col),17,4),'-',
    RIGHT(HEX(col),12)
)) WHERE col IS NOT NULL
```

```php
public function up(Schema $schema): void
{
    // 1. Drop all foreign keys
    $this->addSql('ALTER TABLE manual_contact DROP FOREIGN KEY FK_AE95011B32C8A3DE');
    $this->addSql('ALTER TABLE manual_contact_sync_list DROP FOREIGN KEY FK_E34389B02B6A5820');
    $this->addSql('ALTER TABLE manual_contact_sync_list DROP FOREIGN KEY FK_E34389B0EF4CDF85');
    $this->addSql('ALTER TABLE provider_credential DROP FOREIGN KEY FK_20C951F332C8A3DE');
    $this->addSql('ALTER TABLE sync_list DROP FOREIGN KEY FK_161E54572E32565F');
    $this->addSql('ALTER TABLE sync_list DROP FOREIGN KEY FK_161E545732C8A3DE');
    $this->addSql('ALTER TABLE sync_list DROP FOREIGN KEY FK_161E5457E03476C7');
    $this->addSql('ALTER TABLE sync_run DROP FOREIGN KEY FK_EE38DD732B6A5820');
    $this->addSql('ALTER TABLE sync_run DROP FOREIGN KEY FK_EE38DD739CE7D53B');
    $this->addSql('ALTER TABLE sync_run_contact DROP FOREIGN KEY FK_AF2C0FCD2C62101B');

    // Helper: conversion expression for BINARY(16) → CHAR(36) RFC 4122
    // LOWER(CONCAT(LEFT(HEX(col),8),'-',SUBSTR(HEX(col),9,4),'-',SUBSTR(HEX(col),13,4),'-',SUBSTR(HEX(col),17,4),'-',RIGHT(HEX(col),12)))

    // 2. Convert data and alter columns — parent tables first

    // organization (id NOT NULL)
    $this->addSql("UPDATE organization SET id = LOWER(CONCAT(LEFT(HEX(id),8),'-',SUBSTR(HEX(id),9,4),'-',SUBSTR(HEX(id),13,4),'-',SUBSTR(HEX(id),17,4),'-',RIGHT(HEX(id),12)))");
    $this->addSql('ALTER TABLE organization MODIFY id CHAR(36) NOT NULL');

    // user (id NOT NULL)
    $this->addSql("UPDATE `user` SET id = LOWER(CONCAT(LEFT(HEX(id),8),'-',SUBSTR(HEX(id),9,4),'-',SUBSTR(HEX(id),13,4),'-',SUBSTR(HEX(id),17,4),'-',RIGHT(HEX(id),12)))");
    $this->addSql('ALTER TABLE `user` MODIFY id CHAR(36) NOT NULL');

    // provider_credential (id NOT NULL, organization_id NOT NULL)
    $this->addSql("UPDATE provider_credential SET id = LOWER(CONCAT(LEFT(HEX(id),8),'-',SUBSTR(HEX(id),9,4),'-',SUBSTR(HEX(id),13,4),'-',SUBSTR(HEX(id),17,4),'-',RIGHT(HEX(id),12))), organization_id = LOWER(CONCAT(LEFT(HEX(organization_id),8),'-',SUBSTR(HEX(organization_id),9,4),'-',SUBSTR(HEX(organization_id),13,4),'-',SUBSTR(HEX(organization_id),17,4),'-',RIGHT(HEX(organization_id),12)))");
    $this->addSql('ALTER TABLE provider_credential MODIFY id CHAR(36) NOT NULL, MODIFY organization_id CHAR(36) NOT NULL');

    // manual_contact (id NOT NULL, organization_id NOT NULL)
    $this->addSql("UPDATE manual_contact SET id = LOWER(CONCAT(LEFT(HEX(id),8),'-',SUBSTR(HEX(id),9,4),'-',SUBSTR(HEX(id),13,4),'-',SUBSTR(HEX(id),17,4),'-',RIGHT(HEX(id),12))), organization_id = LOWER(CONCAT(LEFT(HEX(organization_id),8),'-',SUBSTR(HEX(organization_id),9,4),'-',SUBSTR(HEX(organization_id),13,4),'-',SUBSTR(HEX(organization_id),17,4),'-',RIGHT(HEX(organization_id),12)))");
    $this->addSql('ALTER TABLE manual_contact MODIFY id CHAR(36) NOT NULL, MODIFY organization_id CHAR(36) NOT NULL');

    // sync_list (id NOT NULL, organization_id NOT NULL, source_credential_id DEFAULT NULL, destination_credential_id DEFAULT NULL)
    $this->addSql("UPDATE sync_list SET id = LOWER(CONCAT(LEFT(HEX(id),8),'-',SUBSTR(HEX(id),9,4),'-',SUBSTR(HEX(id),13,4),'-',SUBSTR(HEX(id),17,4),'-',RIGHT(HEX(id),12))), organization_id = LOWER(CONCAT(LEFT(HEX(organization_id),8),'-',SUBSTR(HEX(organization_id),9,4),'-',SUBSTR(HEX(organization_id),13,4),'-',SUBSTR(HEX(organization_id),17,4),'-',RIGHT(HEX(organization_id),12))), source_credential_id = CASE WHEN source_credential_id IS NOT NULL THEN LOWER(CONCAT(LEFT(HEX(source_credential_id),8),'-',SUBSTR(HEX(source_credential_id),9,4),'-',SUBSTR(HEX(source_credential_id),13,4),'-',SUBSTR(HEX(source_credential_id),17,4),'-',RIGHT(HEX(source_credential_id),12))) END, destination_credential_id = CASE WHEN destination_credential_id IS NOT NULL THEN LOWER(CONCAT(LEFT(HEX(destination_credential_id),8),'-',SUBSTR(HEX(destination_credential_id),9,4),'-',SUBSTR(HEX(destination_credential_id),13,4),'-',SUBSTR(HEX(destination_credential_id),17,4),'-',RIGHT(HEX(destination_credential_id),12))) END");
    $this->addSql('ALTER TABLE sync_list MODIFY id CHAR(36) NOT NULL, MODIFY organization_id CHAR(36) NOT NULL, MODIFY source_credential_id CHAR(36) DEFAULT NULL, MODIFY destination_credential_id CHAR(36) DEFAULT NULL');

    // sync_run (id NOT NULL, sync_list_id NOT NULL, triggered_by_user_id DEFAULT NULL)
    $this->addSql("UPDATE sync_run SET id = LOWER(CONCAT(LEFT(HEX(id),8),'-',SUBSTR(HEX(id),9,4),'-',SUBSTR(HEX(id),13,4),'-',SUBSTR(HEX(id),17,4),'-',RIGHT(HEX(id),12))), sync_list_id = LOWER(CONCAT(LEFT(HEX(sync_list_id),8),'-',SUBSTR(HEX(sync_list_id),9,4),'-',SUBSTR(HEX(sync_list_id),13,4),'-',SUBSTR(HEX(sync_list_id),17,4),'-',RIGHT(HEX(sync_list_id),12))), triggered_by_user_id = CASE WHEN triggered_by_user_id IS NOT NULL THEN LOWER(CONCAT(LEFT(HEX(triggered_by_user_id),8),'-',SUBSTR(HEX(triggered_by_user_id),9,4),'-',SUBSTR(HEX(triggered_by_user_id),13,4),'-',SUBSTR(HEX(triggered_by_user_id),17,4),'-',RIGHT(HEX(triggered_by_user_id),12))) END");
    $this->addSql('ALTER TABLE sync_run MODIFY id CHAR(36) NOT NULL, MODIFY sync_list_id CHAR(36) NOT NULL, MODIFY triggered_by_user_id CHAR(36) DEFAULT NULL');

    // sync_run_contact (id NOT NULL, sync_run_id NOT NULL)
    $this->addSql("UPDATE sync_run_contact SET id = LOWER(CONCAT(LEFT(HEX(id),8),'-',SUBSTR(HEX(id),9,4),'-',SUBSTR(HEX(id),13,4),'-',SUBSTR(HEX(id),17,4),'-',RIGHT(HEX(id),12))), sync_run_id = LOWER(CONCAT(LEFT(HEX(sync_run_id),8),'-',SUBSTR(HEX(sync_run_id),9,4),'-',SUBSTR(HEX(sync_run_id),13,4),'-',SUBSTR(HEX(sync_run_id),17,4),'-',RIGHT(HEX(sync_run_id),12)))");
    $this->addSql('ALTER TABLE sync_run_contact MODIFY id CHAR(36) NOT NULL, MODIFY sync_run_id CHAR(36) NOT NULL');

    // manual_contact_sync_list (manual_contact_id NOT NULL, sync_list_id NOT NULL)
    $this->addSql("UPDATE manual_contact_sync_list SET manual_contact_id = LOWER(CONCAT(LEFT(HEX(manual_contact_id),8),'-',SUBSTR(HEX(manual_contact_id),9,4),'-',SUBSTR(HEX(manual_contact_id),13,4),'-',SUBSTR(HEX(manual_contact_id),17,4),'-',RIGHT(HEX(manual_contact_id),12))), sync_list_id = LOWER(CONCAT(LEFT(HEX(sync_list_id),8),'-',SUBSTR(HEX(sync_list_id),9,4),'-',SUBSTR(HEX(sync_list_id),13,4),'-',SUBSTR(HEX(sync_list_id),17,4),'-',RIGHT(HEX(sync_list_id),12)))");
    $this->addSql('ALTER TABLE manual_contact_sync_list MODIFY manual_contact_id CHAR(36) NOT NULL, MODIFY sync_list_id CHAR(36) NOT NULL');

    // 3. Re-add all foreign keys
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
    // Not reversible — this is a one-way migration
    $this->throwIrreversibleMigrationException('Cannot convert CHAR(36) UUIDs back to BINARY(16).');
}
```

- [ ] **Step 3: Run the migration**

Run: `php bin/console doctrine:migrations:migrate --no-interaction`
Expected: Migration completes successfully.

- [ ] **Step 4: Verify data was converted correctly**

Run: `php bin/console dbal:run-sql "SELECT id FROM organization LIMIT 1"`
Expected: UUID in RFC 4122 format like `019c9bf7-6cb7-705e-8a47-982764446ebe` (not binary gibberish)

Run: `php bin/console dbal:run-sql "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'contacts_sync' AND COLUMN_NAME = 'id' AND TABLE_NAME = 'organization'"`
Expected: `char(36)`

- [ ] **Step 5: Verify the app still works**

Run: `php bin/console cache:clear && composer run-script test`
Expected: All tests pass. The application should function correctly since the StringUuidType handles CHAR(36) values.

- [ ] **Step 6: Commit**

```bash
git add migrations/
git commit -m "feat: migrate UUID columns from BINARY(16) to CHAR(36)"
```

### Task 7: Delete migration files and clean up

- [ ] **Step 1: Delete all migration files**

```bash
rm -f migrations/Version*.php
```

- [ ] **Step 2: Clear the doctrine_migration_versions table**

Run: `php bin/console dbal:run-sql "TRUNCATE doctrine_migration_versions"`

- [ ] **Step 3: Verify migrations status is clean**

Run: `php bin/console doctrine:migrations:status`
Expected: No migrations to execute, no recorded versions.

- [ ] **Step 4: Verify schema is in sync**

Run: `php bin/console doctrine:schema:validate`
Expected: Schema is in sync with mapping files. If there are differences, check and resolve them (Doctrine may want to add `COMMENT` annotations for the custom type — this is expected).

- [ ] **Step 5: Commit**

```bash
git rm migrations/Version*.php
git commit -m "chore: remove migration files after successful UUID migration"
```

---

## Chunk 5: Documentation and Cleanup

### Task 8: Create Doctrine README and update docs

**Files:**
- Create: `src/Doctrine/README.md`
- Modify: `src/README.md:30-53`
- Modify: `CLAUDE.md:77-88`

- [ ] **Step 1: Create src/Doctrine/README.md**

```markdown
# Custom Doctrine Types

## StringUuidType

**Registered as:** `uuid_string` (in `config/packages/doctrine.yaml`)

### Why

Symfony's built-in `UuidType` stores UUIDs as `BINARY(16)` on MySQL. This causes recurring bugs:

- Raw DBAL queries return 16-byte binary strings, not human-readable UUIDs
- QueryBuilder `IN` clauses silently fail when `Uuid` objects are stringified instead of binarized
- Every `setParameter()` call requires an explicit `UuidType::NAME` type hint — omitting it is a silent failure

`StringUuidType` forces RFC 4122 string storage on all platforms, eliminating these issues.

### How It Works

`StringUuidType` extends Symfony's `UuidType` with two overrides:

- **`getSQLDeclaration()`** — Returns the platform's GUID type declaration (`CHAR(36)` on MySQL, native `UUID` on PostgreSQL) instead of `BINARY(16)`.
- **`convertToDatabaseValue()`** — Always calls `toRfc4122()` to produce a string. The parent's method uses a `private` platform check (`hasNativeGuidType()`) that can't be overridden, so this method is overridden directly.

The parent's `convertToPHPValue()` is inherited as-is — it calls `Uuid::fromString()` to hydrate `Uuid` objects from strings.

### Usage

Entities use `StringUuidType::NAME` as the column type:

```php
use App\Doctrine\Type\StringUuidType;

#[ORM\Column(type: StringUuidType::NAME, unique: true)]
private Uuid $id;
```

### Repository Guidelines

With string UUID storage, repositories need no special handling:

- **No `UuidType::NAME` type hints** — `setParameter('id', $entity->getId())` works without a third argument
- **No binary conversion for `IN` clauses** — pass string arrays directly: `setParameter('ids', $ids)`
- **No `normalizeUuid()` on raw DBAL results** — UUID columns return RFC 4122 strings natively
```

- [ ] **Step 2: Add App\Doctrine to the namespace table in src/README.md**

In `src/README.md`, add a new row to the Namespaces table (after line 32, the `App\Attribute` row):

```
| `App\Doctrine` | Custom Doctrine DBAL types (`StringUuidType` for RFC 4122 string UUID storage) | [Doctrine README](Doctrine/README.md) |
```

- [ ] **Step 3: Update CLAUDE.md**

Replace the entire "Database & UUID Pitfall" section (lines 77-90, from `## Database & UUID Pitfall` through and including the `**Prefer Doctrine QueryBuilder/DQL...` line) with:

```markdown
## Database & UUIDs

UUID columns use the custom `StringUuidType` (registered as `uuid_string`), which stores UUIDs as RFC 4122 strings (`CHAR(36)` on MySQL, native `UUID` on PostgreSQL) and hydrates `Uuid` objects automatically. No binary conversion or special type hints are needed anywhere — see [src/Doctrine/README.md](src/Doctrine/README.md) for details.
```

- [ ] **Step 4: Run tests and CS check**

Run: `composer run-script test && composer run-script cs`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add src/Doctrine/README.md src/README.md CLAUDE.md
git commit -m "docs: add StringUuidType documentation, update CLAUDE.md"
```

### Task 9: Delete design spec and plan files

- [ ] **Step 1: Remove spec and plan files**

```bash
rm -rf docs/superpowers/
```

- [ ] **Step 2: Commit**

```bash
git add -A docs/superpowers/
git commit -m "chore: remove design spec and plan files after implementation"
```

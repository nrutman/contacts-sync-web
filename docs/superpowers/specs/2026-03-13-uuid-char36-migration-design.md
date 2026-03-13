# UUID CHAR(36) Migration Design

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan.

**Goal:** Switch all UUID columns from `BINARY(16)` to `CHAR(36)` on MySQL (native `UUID` on PostgreSQL) to eliminate binary conversion bugs, while preserving `Uuid` object hydration.

**Context:** The `BINARY(16)` storage format has caused recurring bugs:
- Raw DBAL queries return 16-byte binary strings requiring `Uuid::fromBinary()` normalization
- QueryBuilder `IN` clauses silently stringify `Uuid` objects instead of converting to binary, returning 0 results
- Every `setParameter()` call on a UUID column requires an explicit `UuidType::NAME` type hint — forgetting it is a silent failure

**Key discovery:** Symfony's `UuidType` (`AbstractUidType`) checks `hasNativeGuidType()` per platform. PostgreSQL gets native `UUID` and RFC 4122 string storage. MySQL gets `BINARY(16)` because its GUID declaration equals its string declaration, causing `hasNativeGuidType()` to return false. We cannot simply switch to Doctrine's `guid` type because it returns plain strings, breaking `private Uuid $id` property types.

**Solution:** Create a custom `StringUuidType` that extends Symfony's `UuidType` and overrides `getSQLDeclaration()` to always use GUID format. This:
- Preserves `Uuid` object hydration (`convertToPHPValue` returns `Uuid`)
- Stores as RFC 4122 strings via `convertToDatabaseValue` using `toRfc4122()`
- Maps to `CHAR(36)` on MySQL, native `UUID` on PostgreSQL
- Is a drop-in replacement — entities keep `private Uuid $id`, no call site changes

**Scope:** 17 `BINARY(16)` columns across 8 tables; 1 new type class; 7 entities; 4 repositories; CLAUDE.md documentation.

---

## 1. Custom Doctrine Type

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

Register in `config/packages/doctrine.yaml` under the existing `dbal` block:

```yaml
doctrine:
    dbal:
        types:
            uuid_string: App\Doctrine\Type\StringUuidType
```

**Why both overrides are needed:**

- `getSQLDeclaration()` — Forces `CHAR(36)` on MySQL (instead of `BINARY(16)`) and native `UUID` on PostgreSQL.
- `convertToDatabaseValue()` — Forces `toRfc4122()` on all platforms. The parent's `AbstractUidType::hasNativeGuidType()` is `private` and checks the platform directly (not `$this->getSQLDeclaration()`), so overriding the SQL declaration alone does NOT change the conversion behavior. On MySQL, the parent would still call `toBinary()`. This override ensures RFC 4122 string output regardless of platform.

The parent's `convertToPHPValue()` is inherited as-is — it calls `Uuid::fromString($value)`, which handles both binary and RFC 4122 string input, returning a `Uuid` object.

---

## 2. Database Migration

A single hand-written Doctrine migration that converts all existing binary UUID data to RFC 4122 string format.

### Tables and columns (17 total)

| Table | Columns |
|---|---|
| `organization` | `id` |
| `user` | `id` |
| `provider_credential` | `id`, `organization_id` |
| `sync_list` | `id`, `organization_id`, `source_credential_id`, `destination_credential_id` |
| `sync_run` | `id`, `sync_list_id`, `triggered_by_user_id` |
| `sync_run_contact` | `id`, `sync_run_id` |
| `manual_contact` | `id`, `organization_id` |
| `manual_contact_sync_list` | `manual_contact_id`, `sync_list_id` |

### Foreign keys to drop/re-add

Query the database for the exact FK names at migration time. All FKs referencing `BINARY(16)` columns must be dropped before column type changes and re-added after.

### Migration steps

1. **Drop all foreign keys** on UUID columns
2. **For each table, for each UUID column:**
   - `UPDATE table SET col = LOWER(CONCAT(LEFT(HEX(col),8),'-',SUBSTR(HEX(col),9,4),'-',SUBSTR(HEX(col),13,4),'-',SUBSTR(HEX(col),17,4),'-',RIGHT(HEX(col),12))) WHERE col IS NOT NULL`
   - `ALTER TABLE table MODIFY col CHAR(36) [NOT NULL | DEFAULT NULL]` (preserve nullability)
3. **Re-add all foreign keys**
4. **Convert parent PKs before child FKs** — process tables in dependency order: `organization` and `user` first, then `provider_credential` and `sync_list`, then `sync_run`, then `sync_run_contact` and `manual_contact`, then `manual_contact_sync_list`

### Post-migration cleanup

After running the migration successfully on the current instance:
- **Delete all migration files** from `migrations/`
- **Clear the `doctrine_migration_versions` table** (`TRUNCATE doctrine_migration_versions`) so `doctrine:migrations:status` doesn't report missing files
- Fresh installs will use `doctrine:schema:create` and get `CHAR(36)` columns from the start

---

## 3. Entity Changes

All 7 entities follow the same pattern. Change:

```php
use Symfony\Bridge\Doctrine\Types\UuidType;
// ...
#[ORM\Column(type: UuidType::NAME, unique: true)]
```

To:

```php
use App\Doctrine\Type\StringUuidType;
// ...
#[ORM\Column(type: StringUuidType::NAME, unique: true)]
```

Replace the `UuidType` import with `StringUuidType` in each entity. The `Uuid` import stays (used in constructors for `Uuid::v7()`). Property types remain `private Uuid $id` — no changes to types or call sites.

### Entities

- `src/Entity/Organization.php`
- `src/Entity/User.php`
- `src/Entity/SyncList.php`
- `src/Entity/SyncRun.php`
- `src/Entity/SyncRunContact.php`
- `src/Entity/ProviderCredential.php`
- `src/Entity/ManualContact.php`

---

## 4. Repository Simplifications

### SyncListRepository

- Remove `UuidType` import, `ArrayParameterType` import, `Uuid` import
- Remove all `, UuidType::NAME` third arguments from `setParameter()` calls (5 occurrences)
- In `findByOrganizationAndIds()`: remove `toBinary()` conversion and `ArrayParameterType::BINARY` — just pass `$ids` directly as `setParameter('ids', $ids)`

### SyncRunRepository

- Remove `UuidType` import, `Uuid` import
- Remove all `, UuidType::NAME` third arguments from `setParameter()` calls in QueryBuilder methods (8 occurrences: lines 33, 49, 66, 90, 94, 116, 292, 296)
- In `findDestinationCountsByOrganization()` and `findSourceCountsByOrganization()`: remove `'org' => UuidType::NAME` from the DBAL type arrays. These 2 calls have single-entry type arrays that become empty — remove the type arrays entirely.
- In `deleteOlderThan()`: remove `'org' => UuidType::NAME` from the 2 DBAL type arrays but **keep** `'cutoff' => 'datetime_immutable'` — the type arrays shrink to `['cutoff' => 'datetime_immutable']`.
- Delete `normalizeUuid()` method entirely
- Replace 2 call sites of `normalizeUuid()` with direct `$row['list_id']` access

### SyncRunContactRepository

- Remove `UuidType` import
- Remove `, UuidType::NAME` from 2 `setParameter()` calls

### ManualContactRepository

- Remove `UuidType` import
- Remove `, UuidType::NAME` from 1 `setParameter()` call

---

## 5. Documentation

### `src/Doctrine/README.md` (new file)

Create a README for the new `Doctrine` namespace explaining the custom type, why it exists, and how to use it:

- **Why:** Symfony's `UuidType` stores as `BINARY(16)` on MySQL, which causes silent bugs (binary/string mismatches in raw SQL, `IN` clause failures, need for `normalizeUuid()` on results). `StringUuidType` forces RFC 4122 string storage on all platforms.
- **How it works:** Extends Symfony's `UuidType` to override `getSQLDeclaration()` (forces GUID column type) and `convertToDatabaseValue()` (forces `toRfc4122()` output). Inherits `convertToPHPValue()` which hydrates `Uuid` objects from strings.
- **Usage:** Entities use `#[ORM\Column(type: StringUuidType::NAME)]`. Registered in `config/packages/doctrine.yaml` as `uuid_string`.
- **Guidelines for repositories:** No `UuidType::NAME` type hints needed on `setParameter()`. No binary conversion for `IN` clauses. Raw DBAL results return RFC 4122 strings directly.

### `src/README.md` update

Add `App\Doctrine` to the Namespaces table:

| `App\Doctrine` | Custom Doctrine DBAL types (`StringUuidType` for RFC 4122 string UUID storage) | [Doctrine README](Doctrine/README.md) |

### `CLAUDE.md` update

Replace the "Database & UUID Pitfall" section with a shorter section that references the README:

```markdown
## Database & UUIDs

UUID columns use the custom `StringUuidType` (registered as `uuid_string`), which stores UUIDs as RFC 4122 strings (`CHAR(36)` on MySQL, native `UUID` on PostgreSQL) and hydrates `Uuid` objects automatically. No binary conversion or special type hints are needed anywhere — see [src/Doctrine/README.md](src/Doctrine/README.md) for details.
```

---

## 6. Tests

- **New test:** `tests/Doctrine/Type/StringUuidTypeTest.php` for the custom type:
  - `getSQLDeclaration()` returns GUID format (not BINARY)
  - `convertToDatabaseValue()` returns RFC 4122 string for `Uuid` objects, string inputs, and null
  - `convertToPHPValue()` returns `Uuid` object from RFC 4122 string and null
- Run the full test suite after entity and repository changes
- Update any tests that reference `UuidType::NAME` — change to `StringUuidType::NAME`
- Entity `getId()` still returns `Uuid` objects, so tests calling `->equals()`, `->toRfc4122()`, etc. are unaffected

---

## 7. Cleanup

After all changes are verified and tests pass:

- Delete the design spec file `docs/superpowers/specs/2026-03-13-uuid-char36-migration-design.md`
- Remove the `docs/superpowers/` directory if empty

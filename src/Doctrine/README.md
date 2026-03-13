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

`StringUuidType` extends Symfony's `AbstractUidType` with two overrides:

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

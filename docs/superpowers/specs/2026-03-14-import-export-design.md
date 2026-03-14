# Import/Export CLI Commands Design

## Overview

Two CLI commands (`app:export` and `app:import`) to move configuration data between application instances as a single JSON file. Credentials are decrypted on export and re-encrypted on import using the target instance's encryption key.

## Scope

**Included entities:** Organization, ProviderCredential, SyncList, ManualContact (with all relationships).

**Excluded:** User (password security), SyncRun/SyncRunContact (operational history, not configuration).

## Export Command (`app:export`)

**Signature:** `php bin/console app:export <path>`

**Behavior:**
1. Fetch the single Organization via `OrganizationRepository::findOne()`.
2. Load all ProviderCredentials, SyncLists, and ManualContacts for the organization via their respective repositories.
3. Build a JSON structure with preserved UUIDs and decrypted credential data.
4. Write to the specified file path with `0600` permissions (contains plaintext credentials).

**JSON structure:**
```json
{
  "version": 1,
  "exportedAt": "2026-03-14T12:00:00+00:00",
  "organization": {
    "id": "uuid",
    "name": "string",
    "retentionDays": "int|null"
  },
  "providerCredentials": [
    {
      "id": "uuid",
      "providerName": "string",
      "label": "string|null",
      "credentials": { "...decrypted JSON..." },
      "metadata": { "...json or null..." }
    }
  ],
  "syncLists": [
    {
      "id": "uuid",
      "name": "string",
      "sourceCredentialId": "uuid|null",
      "sourceListIdentifier": "string|null",
      "destinationCredentialId": "uuid|null",
      "destinationListIdentifier": "string|null",
      "isEnabled": "bool",
      "cronExpression": "string|null",
      "manualContactIds": ["uuid", "..."]
    }
  ],
  "manualContacts": [
    {
      "id": "uuid",
      "name": "string",
      "email": "string"
    }
  ]
}
```

**Notes:**
- `organization` is a single object (not an array) since the app is single-tenant.
- ProviderCredential `credentials` field is the decrypted JSON object (via `getCredentialsArray()`), not the encrypted ciphertext.
- SyncList includes `manualContactIds` to capture the many-to-many relationship.
- SyncList `sourceCredentialId` and `destinationCredentialId` reference ProviderCredential UUIDs in the same export.
- Timestamps (`createdAt`, `updatedAt`) are not preserved; imported entities receive fresh timestamps.

**Exit codes:** 0 on success, 1 on failure (no organization found, file write error).

## Import Command (`app:import`)

**Signature:** `php bin/console app:import <path> [--force]`

**Behavior:**
1. Read and validate the JSON file:
   - File exists and is readable.
   - Valid JSON.
   - `version` field equals 1 (if > 1, abort with "unsupported version" error).
   - Required top-level keys present: `organization`, `providerCredentials`, `syncLists`, `manualContacts`.
2. Check for existing data (any Organizations via `OrganizationRepository::findOne()`).
   - If data exists and `--force` not set and interactive: prompt for confirmation.
   - If data exists and `--force` not set and non-interactive: abort with error, exit code 1.
   - If user declines prompt: abort, exit code 2.
3. Execute in a single database transaction (both wipe and import are inside the transaction, so a failure at any point rolls back everything):
   - **Wipe phase:** Remove the Organization entity via `$em->remove($organization)` and flush. Doctrine cascade-remove handles all child entities (SyncLists → SyncRuns → SyncRunContacts, ProviderCredentials, ManualContacts, join table entries).
   - **Import phase:**
     1. Create Organization with preserved UUID via Reflection (entities generate UUID v7 in the constructor; the private `$id` property has no setter). Use `ReflectionProperty::setValue()` to override the generated ID.
     2. Create ProviderCredentials with preserved UUIDs (same Reflection approach). `setCredentialsArray()` sets plaintext; `EncryptedFieldListener` encrypts on flush. Maintain a `uuid → entity` map for later reference resolution.
     3. Create ManualContacts with preserved UUIDs (same Reflection approach), associated to Organization. Maintain a `uuid → entity` map.
     4. Create SyncLists with preserved UUIDs (same Reflection approach). Resolve `sourceCredentialId`/`destinationCredentialId` to ProviderCredential entities via the UUID map. For each `manualContactIds` entry, look up the ManualContact from the UUID map and call `$syncList->addManualContact($manualContact)` (which internally calls `$manualContact->addSyncList()` to update the owning side of the ManyToMany).
4. Flush — encryption happens automatically via `EncryptedFieldListener`.
5. Print summary of imported entity counts.

**Error handling:** Any failure rolls back the transaction. Invalid JSON, missing required fields, or unresolvable UUID references produce clear error messages.

**Exit codes:** 0 on success, 1 on failure, 2 on user abort.

## Serialization Approach

Manual serialization in the command classes. Each command builds/reads the JSON arrays directly from entity getters/setters. No Symfony Serializer, no `toExportArray()` methods on entities — keeps import/export concerns in the commands.

## Security Note

The export file contains **plaintext credentials** (OAuth tokens, API keys). The file is written with `0600` permissions. Users should handle the export file with care and delete it after import.

## Testing

### ExportCommandTest
- Mock repositories to return known entities with predictable data.
- Execute via `CommandTester`.
- Assert the written JSON has correct structure, field values, and decrypted credentials.
- Assert exit code 0.
- Test failure case: no organization found → error message, exit code 1.

### ImportCommandTest
- **Happy path:** Provide valid JSON file, verify entities are persisted with correct fields and relationships (credential references, manual contact associations via the owning side).
- **Existing data without `--force` (interactive):** Assert command prompts, simulated decline → abort, exit code 2.
- **Existing data without `--force` (non-interactive):** Assert abort with error, exit code 1.
- **Existing data with `--force`:** Assert wipe + import succeeds, exit code 0.
- **Invalid file cases:** Missing file, malformed JSON, wrong/unsupported version, missing required fields → error messages, exit code 1.

All tests use Mockery for repositories/EntityManager, `CommandTester` for execution, following existing project conventions.

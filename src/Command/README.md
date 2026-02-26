# Command Namespace

Console commands registered via Symfony's `autoconfigure` and invoked through `bin/console`. User-facing documentation (options, arguments, examples) lives in the [project README](../../README.md#-usage).

## Commands

| Command | Description |
|---------|-------------|
| `sync:run` | Syncs contacts from source to destination for all enabled lists (or a specific list via `--list`). Delegates to `SyncService` with `trigger: 'cli'`. |
| `sync:configure` | Provisions OAuth tokens for provider credentials that require them. Iterates all credentials, finds OAuth-requiring ones, and walks through the authentication flow. |
| `source:refresh` | Refreshes a source provider list so it contains the most up-to-date contacts. Accepts a list name or `all`. Currently supports Planning Center lists. |
| `app:setup` | Interactive first-run wizard. Walks through database connection, encryption key generation, email configuration, writes `.env.local`, runs migrations, optionally imports legacy config via `--legacy-config`, and creates the first admin user. |
| `app:create-user` | Creates a new user account interactively. CLI-created users are auto-verified (they can log in immediately without an invitation email). Pass `--admin` to grant `ROLE_ADMIN`. |
| `app:migrate-config` | One-time migration that reads a legacy `parameters.yml` file (passed as a required argument) and `var/google-token.json`, then populates the database (`Organization`, `ProviderCredential`, `SyncList`, `InMemoryContact` entities). Prompts for confirmation if data already exists. |
| `app:rotate-encryption-keys` | Re-encrypts all `#[Encrypted]` entity fields with the current encryption key. Used after rotating `APP_ENCRYPTION_KEY`. Supports `--dry-run` and `--force`. |

## Behavioral Notes

- **`sync:run`** reads `SyncList` entities from the database. It processes each list sequentially, with removals applied before additions. Source contacts are merged from the source provider and in-memory contacts — on email collision, the source provider version is kept.
- **`sync:configure`** iterates all `ProviderCredential` entities, checks if their provider implements `OAuthProviderInterface`, and walks through the OAuth flow for those that need it. Credentials that already have a token are skipped.
- **`source:refresh`** looks up the source provider from each sync list's source credential and calls its `refreshList()` method. Currently, only `PlanningCenterProvider` supports this operation.
- **`app:setup`** is idempotent-safe — it checks for existing data and prompts before overwriting. It is the recommended entry point for first-time deployment.
- **`app:migrate-config`** runs inside a database transaction. If any step fails, nothing is committed. It creates `ProviderCredential` entities for Planning Center and Google Groups from the legacy YAML config, and sets up sync lists with source/destination references.
- **`app:rotate-encryption-keys`** discovers encrypted entities via Doctrine metadata reflection. It reads the raw (encrypted) column values from the unit of work to determine which fields need rotation, then decrypts with the old key and re-encrypts with the current key.

# ⚡ Command Namespace

Console commands registered via Symfony's `autoconfigure` and invoked through `bin/console`. User-facing documentation (options, arguments, examples) lives in the [project README](../../README.md#-usage).

## Commands

| Command | Description |
|---------|-------------|
| `sync:run` | Syncs contacts from Planning Center to Google Groups for all enabled lists (or a specific list via `--list`). Delegates to `SyncService` with `trigger: 'cli'`. |
| `sync:configure` | Provisions a Google OAuth token via a CLI paste flow. No-op when a valid token already exists unless `--force` is passed. |
| `planning-center:refresh` | Refreshes a Planning Center list so it contains the most up-to-date contacts. Accepts a list name or `all`. |
| `app:setup` | Interactive first-run wizard. Walks through database connection, encryption key generation, email configuration, writes `.env.local`, runs migrations, optionally imports legacy config via `--legacy-config`, and creates the first admin user. |
| `app:create-user` | Creates a new user account interactively. CLI-created users are auto-verified (they can log in immediately without an invitation email). Pass `--admin` to grant `ROLE_ADMIN`. |
| `app:migrate-config` | One-time migration that reads a legacy `parameters.yml` file (passed as a required argument) and `var/google-token.json`, then populates the database (`Organization`, `SyncList`, `InMemoryContact` entities). Prompts for confirmation if data already exists. |
| `app:rotate-encryption-keys` | Re-encrypts all `#[Encrypted]` entity fields with the current encryption key. Used after rotating `APP_ENCRYPTION_KEY`. Supports `--dry-run` and `--force`. |

## Behavioral Notes

- **`sync:run`** reads `SyncList` entities from the database. It processes each list sequentially, with removals applied before additions. Source contacts are merged from Planning Center and in-memory contacts — on email collision, the Planning Center version is kept.
- **`sync:configure`** is a no-op when a valid token already exists unless `--force` is passed. In the web application, Google OAuth is handled through the web redirect flow in `SettingsController` instead.
- **`planning-center:refresh`** validates the list name argument against configured lists before making any API calls.
- **`app:setup`** is idempotent-safe — it checks for existing data and prompts before overwriting. It is the recommended entry point for first-time deployment.
- **`app:migrate-config`** runs inside a database transaction. If any step fails, nothing is committed. It reads secrets directly from the YAML file passed as the `config-file` argument.
- **`app:rotate-encryption-keys`** discovers encrypted entities via Doctrine metadata reflection. It reads the raw (encrypted) column values from the unit of work to determine which fields need rotation, then decrypts with the old key and re-encrypts with the current key.
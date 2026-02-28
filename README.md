# Contacts Sync

A Symfony web application that syncs contacts between configurable source and destination providers. Out of the box, it supports Planning Center as a source and Google Groups as a destination, but the provider architecture is extensible to support additional integrations.

```mermaid
flowchart LR
    Source[Source Provider] --> Merge[Merge & Deduplicate]
    Mem[In-Memory Contacts] --> Merge
    Merge --> Diff[Compute Diff]
    Diff --> Dest[Destination Provider]
```

## Installation

All dependencies can be installed using the [Composer PHP dependency manager](https://getcomposer.org/). Once Composer is installed, [download this repository](https://github.com/nrutman/contacts-sync/releases) and run the following command:
```bash
composer install
```

## Configuration

All configuration (provider credentials, sync lists, in-memory contacts) is managed through the web UI after running the setup wizard.

1. Run `bin/console app:setup` to configure the database, encryption key, and create the first admin user.
2. Log in to the web interface and navigate to **Credentials** to add provider credentials (e.g. Planning Center API keys, Google Groups OAuth).
3. Create sync lists and in-memory contacts through the web UI.

If you are migrating from the legacy CLI version, pass `--legacy-config` to the setup wizard to import your old `parameters.yml`:

```bash
bin/console app:setup --legacy-config config/parameters.yml
```

Or run the migration command directly:

```bash
bin/console app:migrate-config config/parameters.yml
```

## Usage

### `sync:configure`

To configure OAuth-based providers (e.g. Google Groups) by provisioning a token, run:
```bash
bin/console sync:configure
```
The command will find all OAuth-requiring provider credentials and walk you through the authentication flow. If a valid token has already been provisioned, the command will skip that credential.

### `sync:run`

To sync contacts between lists, simply run the following command:
```bash
bin/console sync:run
```
This will fetch the lists, run a diff, and display information for changes it is making to the destination.

| Parameter   | Description |
| ----------- | ----------- |
| --dry-run   | Computes the diff and outputs data without actually updating the destination. |
| --list      | Only sync a specific list (by name). |
| --scheduled | Only sync lists that are due according to their cron expression. See [System Cron](#system-cron-alternative). |

### `source:refresh`

Refreshes source provider lists so they contain the most up-to-date contacts. Currently applicable to Planning Center lists, which are computed on-demand.

```bash
# Refresh a single list
bin/console source:refresh list@example.com

# Refresh all enabled lists
bin/console source:refresh all
```

| Argument   | Description |
|------------|-------------|
| list-name  | The name of the list to refresh. Pass `all` to refresh all enabled lists. |

## Production Deployment

### Prerequisites

- PHP 8.5+ with `sodium`, `intl`, and either `pdo_pgsql` or `pdo_mysql` extension
- PostgreSQL 16+ or MySQL 8.0+
- A web server (nginx + php-fpm, Caddy, or Apache)
- SSL/TLS certificate (required for secure cookies and OAuth callbacks)
- SMTP server or transactional email service for outbound email

### Deployment Checklist

```bash
# 1. Install dependencies without dev packages
composer install --no-dev --optimize-autoloader

# 2. Run the interactive setup wizard (first deploy only)
#    Configures the database, encryption key, mailer, creates the schema,
#    and creates the first admin user. Safe to re-run — existing values
#    are preserved by default.
php bin/console app:setup
#    To import legacy CLI config:
#    php bin/console app:setup --legacy-config config/parameters.yml

# 3. Set environment to production
#    In .env.local or your hosting environment:
#    APP_ENV=prod
#    APP_DEBUG=0

# 4. Clear and warm the production cache
php bin/console cache:clear --env=prod

# 5. Compile frontend assets
php bin/console tailwind:build --minify
php bin/console asset-map:compile
```

### Sync Execution

Syncs and source refreshes triggered from the web UI run **synchronously** during the HTTP request — no background worker is required for the web interface to function. The "Sync All" dashboard action uses AJAX to sync each list sequentially with a progress dialog, falling back to a single synchronous POST if JavaScript is unavailable.

### Scheduled Syncs

If you configure cron expressions on sync lists, you have two options for running them automatically:

#### Option A: System Cron (recommended for simple setups)

Use a system-level cron job with the `--scheduled` flag. This evaluates each sync list's cron expression against its last run time and only syncs lists that are due:

```bash
# Run every minute — the command itself determines which lists are due
* * * * * cd /path/to/contacts-sync && php bin/console sync:run --scheduled --env=prod >> var/log/cron.log 2>&1
```

The `--scheduled` flag:
- Skips lists without a cron expression configured
- Runs lists that have never been synced
- Compares each list's cron expression against the last sync run time to determine if a sync is due
- Returns success when no lists are due (normal for cron)
- Combines with `--dry-run` and `--list` for testing

#### Option B: Messenger Worker (for Symfony Scheduler integration)

If you prefer Symfony's built-in Scheduler component, run the Messenger worker:

```bash
php bin/console messenger:consume async scheduler_sync --time-limit=3600
```

Create `/etc/systemd/system/contacts-sync-worker.service`:

```ini
[Unit]
Description=Contacts Sync Messenger Worker
After=network.target

[Service]
ExecStart=/usr/bin/php /path/to/contacts-sync/bin/console messenger:consume async scheduler_sync --time-limit=3600
Restart=always
RestartSec=5
User=www-data
WorkingDirectory=/path/to/contacts-sync
Environment=APP_ENV=prod

[Install]
WantedBy=multi-user.target
```

Then enable and start it:

```bash
sudo systemctl enable contacts-sync-worker
sudo systemctl start contacts-sync-worker
```

The `--time-limit=3600` flag restarts the worker every hour to prevent memory leaks. systemd's `Restart=always` brings it back up immediately.

### Encryption Key Management

The `APP_ENCRYPTION_KEY` env var holds the 64-character hex key used to encrypt sensitive data at rest (API keys, OAuth tokens). For production, use Symfony Secrets:

```bash
php bin/console secrets:set APP_ENCRYPTION_KEY --env=prod
```

To rotate keys, set the old key as a previous key and generate a new current key:

```bash
# 1. Move current key to previous keys list
#    APP_PREVIOUS_ENCRYPTION_KEYS="1:<old-64-char-hex-key>"

# 2. Generate and set a new current key
#    APP_ENCRYPTION_KEY=<new-64-char-hex-key>

# 3. Re-encrypt all data with the new key
php bin/console app:rotate-encryption-keys --force
```

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Google authentication errors | Run `bin/console sync:configure` to set up or refresh your Google OAuth token. |
| Google token keeps expiring | Re-run `sync:configure` to get a new refresh token with offline access. |
| `The list 'X' could not be found` | The list name does not match any source provider list. Verify the exact name in the source system. |
| `Unknown list specified: X` | The list name passed to `source:refresh` is not a configured sync list. Use `all` or a valid list name. |

## Technical Documentation

For architecture details, the sync algorithm, and developer guidance, see the [src/README.md](src/README.md). Each namespace within `src/` also contains its own README with implementation-specific documentation.

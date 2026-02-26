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

| Parameter | Description |
| --------- | ----------- |
| --dry-run | Computes the diff and outputs data without actually updating the destination. |
| --list    | Only sync a specific list (by name). |

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

- PHP 8.5+ with `sodium`, `pdo_pgsql`, and `intl` extensions
- PostgreSQL 16+
- A web server (nginx + php-fpm, Caddy, or Apache)
- SSL/TLS certificate (required for secure cookies and OAuth callbacks)
- SMTP server or transactional email service for outbound email

### Deployment Checklist

```bash
# 1. Set environment to production
#    In .env.local or your hosting environment:
#    APP_ENV=prod
#    APP_DEBUG=0

# 2. Install dependencies without dev packages
composer install --no-dev --optimize-autoloader

# 3. Clear and warm the production cache
php bin/console cache:clear --env=prod

# 4. Compile frontend assets
php bin/console asset-map:compile
php bin/console tailwind:build --minify

# 5. Run database migrations
php bin/console doctrine:migrations:migrate --no-interaction

# 6. Run the interactive setup wizard (first deploy only)
#    This configures the database, encryption key, mailer, and creates
#    the first admin user. Use --legacy-config to import a parameters.yml.
php bin/console app:setup
#    Or, to import legacy CLI config:
#    php bin/console app:setup --legacy-config config/parameters.yml

# 7. Configure MAILER_DSN for outbound email delivery
#    Example: MAILER_DSN=smtp://user:pass@smtp.example.com:587
#    Set MAILER_FROM to the sender address (e.g. noreply@your-domain.com)

# 8. Start the Messenger worker (see systemd unit below)
php bin/console messenger:consume async scheduler_sync --time-limit=3600
```

### Messenger Worker (systemd)

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

The `--time-limit=3600` flag restarts the worker every hour to prevent memory leaks. systemd's `Restart=always` brings it back up immediately. The worker processes both manually triggered syncs and scheduled syncs defined via cron expressions on sync lists.

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

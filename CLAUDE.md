# CLAUDE.md

## Project Context

Read these READMEs to understand the codebase before making changes:

- [README.md](README.md) — installation, configuration, and usage
- [src/README.md](src/README.md) — architecture, sync pipeline, data model, async processing, project structure, developer guide
- [src/Client/README.md](src/Client/README.md) — client design and interface contracts
- [src/Client/Google/README.md](src/Client/Google/README.md) — Google OAuth lifecycle and token management
- [src/Client/PlanningCenter/README.md](src/Client/PlanningCenter/README.md) — Planning Center API integration and pagination
- [src/Command/README.md](src/Command/README.md) — CLI commands (sync, setup, user management, config migration, key rotation)
- [src/Contact/README.md](src/Contact/README.md) — diff algorithm
- [src/Security/README.md](src/Security/README.md) — encryption, key rotation, user verification, invitation flow
- [src/Entity/README.md](src/Entity/README.md) — entity relationship diagram (Mermaid ERD) and entity descriptions
- [src/Sync/README.md](src/Sync/README.md) — sync service orchestration and result DTO
- [src/Frontend/README.md](src/Frontend/README.md) — front-end architecture, Shadcn components, theming, Stimulus controllers

## Commands

Run tests:
```
composer run-script test
```

Check code style:
```
composer run-script cs
```

Fix code style:
```
composer run-script cs-fix
```

## Workflow Requirements

Before making any commits, verify you are on a feature branch — never commit directly to `main`.

After ANY code change, always run both tests and code style checking:
```
composer run-script test && composer run-script cs
```

If `cs` reports violations, fix them with `composer run-script cs-fix`, then re-run tests to confirm nothing broke.

## PR CI Monitoring

After pushing to a branch with an open PR, monitor the CI checks using `gh pr checks`. If any checks fail, investigate the failure, fix the issue, push the fix, and continue monitoring until all checks pass. Repeat this cycle as needed — do not consider the push complete until all CI checks are green.

When additional commits significantly change the scope or impact of a PR, update the PR title and description to reflect the full set of changes.

## Testing Conventions

- Tests live in `tests/` and mirror the `src/` directory structure (e.g. `src/Contact/ContactListAnalyzer.php` → `tests/Contact/ContactListAnalyzerTest.php`).
- Test classes extend `Mockery\Adapter\Phpunit\MockeryTestCase`, not PHPUnit's base `TestCase`.
- Use [Mockery](https://github.com/mockery/mockery) (`Mockery as m`) for mocking, not PHPUnit's built-in mock builder.
- Command tests use Symfony's `CommandTester` to execute commands and assert on output/status codes.
- Data-driven tests use PHPUnit's `#[DataProvider]` attribute with a static provider method.
- When adding a new class, add a corresponding test file. When modifying a class, update or extend its existing tests.
- After writing or updating tests, re-evaluate by asking: (1) Are there any high-value cases we're missing? (2) Can the tests be simplified or consolidated? (3) Are there any low-value tests we can remove?

## Code Style

- The project enforces Symfony + PSR-12 rules via PHP-CS-Fixer (config: `.php-cs-fixer.dist.php`).
- No Yoda conditions — write `$x === true`, not `true === $x`.
- Use short array syntax (`[]`, not `array()`).
- Do not use `phpdoc_to_comment` conversion — multi-line `/** */` annotations are allowed above any statement.

## Architecture Notes

- PHP >=8.3 with Symfony 7.2. Use constructor promotion and PHP 8+ features (attributes, named arguments, readonly properties, etc.) where appropriate, but avoid features requiring PHP 8.4+ (property hooks, asymmetric visibility, `array_find()`, etc.).
- PSR-4 autoloading: `App\` → `src/`, `App\Tests\` → `tests/`.
- Symfony autowiring and autoconfigure are enabled. New services placed in `src/` are registered automatically — no manual service definitions needed unless non-standard wiring is required.
- Doctrine ORM entities live in `src/Entity/` and are excluded from the service container. Repositories in `src/Repository/` are auto-registered. When adding, removing, or changing entities, update both the Mermaid ERD and the entity descriptions in [src/Entity/README.md](src/Entity/README.md) to match.
- Sensitive data (API keys, OAuth tokens) is stored encrypted in the database (PostgreSQL or MySQL) via `#[Encrypted]` attribute + `EncryptedFieldListener`. The encryption key is in `APP_ENCRYPTION_KEY` env var — never commit it.
- API credentials, sync lists, and manual contacts are stored in the database via `Organization` entities. The legacy `parameters.yml` file has been removed; use `app:migrate-config <file>` to import old config.
- Sync and refresh operations from the web UI are dispatched via Symfony Messenger (async transport). The worker (`messenger:consume async scheduler_sync`) must be running to process them.
- Symfony Scheduler reads cron expressions from `SyncList` entities via `SyncScheduleProvider`. The `ScheduleCacheInvalidator` listener clears the scheduler cache when lists change.
- `var/` contains runtime artifacts and is not committed to version control.

## Database & UUIDs

UUID columns use the custom `StringUuidType` (registered as `uuid_string`), which stores UUIDs as RFC 4122 strings (`CHAR(36)` on MySQL, native `UUID` on PostgreSQL) and hydrates `Uuid` objects automatically. No binary conversion or special type hints are needed anywhere — see [src/Doctrine/README.md](src/Doctrine/README.md) for details.
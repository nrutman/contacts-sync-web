# Sync Namespace

This namespace contains the core sync orchestration logic, extracted from the original `RunSyncCommand` into a reusable service.

## SyncService

The central service that executes the sync pipeline. It is consumed by three entry points:

| Caller | Trigger value | How it invokes |
|--------|---------------|----------------|
| `RunSyncCommand` | `cli` | Calls `executeSync()` directly |
| `SyncMessageHandler` | `manual` or `schedule` | Calls `executeSync()` after resolving entities from message IDs |
| `DashboardController::runAll` | `manual` | Dispatches `SyncMessage` via Messenger (async) |

### Execution Flow

`executeSync()` accepts a `SyncList`, optional flags, and an optional pre-created `SyncRun`. It:

1. Creates or resumes a `SyncRun` entity (status → `running`).
2. Looks up the source and destination providers via `ProviderRegistry` using the `SyncList`'s credential references.
3. Builds API clients by calling `createClient()` on each provider with the appropriate `ProviderCredential`.
4. Fetches source contacts from the source provider, merges with `ManualContact` entities for the list, and deduplicates by email.
5. Fetches destination contacts from the destination provider.
6. Computes the diff via `ContactListAnalyzer`.
7. Applies additions and removals (or skips them in dry-run mode).
8. Records results to the `SyncRun` and dispatches a `SyncCompletedEvent`.

On any exception, the `SyncRun` is marked `failed` with the error message, the event is still dispatched (so failure notifications are sent), and the error is returned in the `SyncResult`.

### Pre-created SyncRun Pattern

When triggered from the web UI, the controller creates a `SyncRun` with status `pending` *before* dispatching the async message. This gives the user immediate visual feedback. The message includes the `syncRunId`, and the handler passes the existing `SyncRun` to `executeSync()` so it resumes the same record rather than creating a duplicate.

## SyncResult

A readonly DTO returned by `executeSync()` containing the outcome: source/destination counts, added/removed counts, log output, success flag, and optional error message. This is used by the CLI command to display results and by the message handler to determine if the sync succeeded.

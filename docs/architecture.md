# Migration state and database contract

## Runtime flow

`MigrationSystem` creates a `MigrationManager` through `MigrationManagerFactory`.
The manager determines registration order and delegates each state transition to
`MigrationLifecycle`. The lifecycle executes SQL through `WordPressSqlExecutor`
and stores current state plus append-only history through `MigrationTracker`.

| Operation | Ordered steps | History direction |
|---|---|---|
| `migrate` | ensure metadata tables → run `up()` SQL → save current record → append history | `up` |
| `rollback` | run `down()` SQL → delete current record → append history | `down` |
| `mark up` | ensure metadata tables → save current record → append history | `mark_up` |
| `mark down` | delete current record → append history | `mark_down` |

Forward migrations run in registration order. Rollbacks run in reverse
registration order. A target identifies a registered version, full class name,
or short class name. Processing stops on the first failed step.

## CLI rollback boundary

WP-CLI database rollback is allowed only when the loaded WordPress
`wp_get_environment_type()` reports `local` or `development`. Production,
staging, unknown values and an unavailable WordPress environment API fail
closed. `wp migration rollback` (all plugins, one plugin or one migration),
`execute --down`, and backward `migrate` targets (including the `run` alias)
are blocked. No force flag bypasses the restriction. Forward migration and
`execute --up` remain available in every environment.

The CLI passes `allowRollback: false` to `MigrationManager::migrateTo()` in
restricted environments. The manager rejects the actual backward branch before
calling `down()` or deleting applied state, rather than trusting a separate CLI
status check that could become stale. Concurrent forward work cannot turn this
call into a rollback. The library default remains `allowRollback: true` for
explicitly reviewed inverse migrations; direct library calls do not infer a
WordPress environment. Metadata-only `version --add/--delete` remains an
explicit reconciliation operation and does not execute migration SQL.

## State model

- `{$wpdb->prefix}migrations` contains the current version for each
  `(plugin, stable migration key)` pair.
- `{$wpdb->prefix}migration_history` is append-only execution history; newest
  entries are returned first.
- A `schema:<hash>` version changes on any unequal hash. Other versions use
  `version_compare()` and only move forward automatically.
- Re-running an up-to-date migration is a no-op.
- Metadata tables are removed only when auto-cleanup is explicitly enabled and
  no current records remain.

The WordPress executor holds a database advisory lock around each actual
operation, rechecks applied state after acquiring it, and releases it in a
`finally` block. The lock includes database, WordPress prefix and plugin scope.
DML-only operations use a transaction spanning SQL, state and history on
transactional tables. State/history writes also use a transaction after DDL.
MySQL/MariaDB DDL can commit independently and is never claimed to be atomic.
A DDL or metadata failure returns false, stops ordering and emits a credential
free reconciliation warning; previously executed DDL may remain. Reconcile the
schema before retrying. Custom SQL executors must implement
`MigrationOperationExecutor` to provide the equivalent operation boundary.

## SQL executor contract

- Empty statements are ignored; arrays execute sequentially and stop on the
  first failure.
- A trimmed statement beginning with `CREATE TABLE` (case-insensitive) runs
  through WordPress `dbDelta()`.
- Every other statement runs through `$wpdb->query()`.
- Before `dbDelta()`, the executor loads WordPress's upgrade library when
  needed and clears `$wpdb->last_error`; any resulting error fails the step.
- Statements are supplied by trusted migration classes. Dynamic identifiers or
  values must still be escaped/prepared by the migration author.

`tests/Support/TestEnvironment.php` is an in-memory `wpdb` contract double, not
a real database. `composer tests:database` boots the installed WordPress core
against a disposable MariaDB database and probes `dbDelta()`, ordinary queries,
schema changes, current state, and append-only history through the real `wpdb`
implementation. Run it for every database-boundary or schema-engine-specific
change.
The bootstrap requires an explicit `WORDPRESS_DB_NAME=sympress_review_*` value;
it refuses general application databases. Required database CI fails skipped or
incomplete tests and runs on pull requests, main and the weekly schedule.

## Identity, deployment and table scoping

Named migration classes keep their class identity unless they declare a stable
`getMigrationKey(): string`. Anonymous migrations **require** that method;
basename/line and release-directory heuristics cannot establish unique identity.
Explicit keys must be non-empty, contain no NUL and fit 255 bytes. The ORM bridge
uses `orm-schema:<manager>`. Versions remain separate from keys. Collections are
indexed by these keys, support multiple instances of an anonymous declaration
with distinct keys, and reject overlapping keys/legacy aliases before execution.
Class-based lookup of multiple such instances is ambiguous; use the stable key.
Registering a different object with an existing key is an error. A manager that
intentionally updates a registered schema definition in one process must use
`replaceMigration()`; it preserves registration order and validates every legacy
identity against the other registrations. Unknown replacement keys fail.

Before upgrading old anonymous migrations, inspect the recorded state and
explicitly map each exact historical identity through
`getLegacyMigrationKeys(): array` (a list of recorded strings, including PHP's
NUL-separated anonymous class name). Never guess identity from a filename, line
or schema hash. Unmapped anonymous state stops migration/rollback/mark operations
before migration SQL or metadata DDL. Historical records whose migration was
removed must be explicitly reconciled by the operator with backups and history
retained. Named classes automatically recognize their own old class key.

Saving a new current version or marking it up retires mapped obsolete applied
keys in the same metadata transaction and retains append-only history. Rollback
and mark-down remove all applied aliases, so old state cannot reappear. Multiple
legacy aliases with conflicting versions require explicit reconciliation.
An up-to-date legacy record remains valid; `mark up` can explicitly move it to
the canonical key without executing SQL. Forward targets rescan all pending
migrations through that target in registration order, including changed earlier
schema hashes; a failure prevents later migrations from running.

Default state/history tables use the current site's `$wpdb->prefix`, including
its multisite blog prefix. A custom state table produces `<state_table>_history`
as the matching history table. MigrationSystem initialization only registers
hooks; it no longer probes/creates metadata tables on each admin request.
Storage is initialized explicitly before migration or mark operations. LIKE
table lookup escapes wildcard characters. Auto-cleanup remains opt-in.

The WordPress PHPStan profile checks metadata queries, which use direct prepared
constant templates with `%i` identifiers and bound values. The executor has one
reasoned `sympress.preparedSql` suppression for the explicit trusted migration
SQL contract. Custom metadata table identifiers are validated before DDL.

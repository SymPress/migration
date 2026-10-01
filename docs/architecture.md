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

## Identity, deployment and table scoping

Named migration classes keep their class identity. Anonymous migration keys drop
the absolute release directory and retain source basename plus declaration line;
existing applied class records are found through the same normalization and are
preserved. Anonymous migrations whose source declaration moves should expose a
stable `getMigrationKey(): string`; explicit keys must be non-empty and fit 255
bytes. The ORM bridge uses `orm-schema:<manager>` so its identity also survives
source edits. Versions remain separate from keys.

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

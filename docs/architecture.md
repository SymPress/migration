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
  `(plugin, migration class)` pair.
- `{$wpdb->prefix}migration_history` is append-only execution history; newest
  entries are returned first.
- A `schema:<hash>` version changes on any unequal hash. Other versions use
  `version_compare()` and only move forward automatically.
- Re-running an up-to-date migration is a no-op.
- Metadata tables are removed only when auto-cleanup is explicitly enabled and
  no current records remain.

There is no transaction spanning migration SQL, current state, and history.
Consequently, a metadata failure after successful SQL can leave database and
metadata state different. Do not reorder these writes or add retry behavior
without defining and testing the recovery contract.

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

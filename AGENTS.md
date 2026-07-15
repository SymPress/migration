# SymPress Migration

## Scope and entry points

- Read `docs/architecture.md` before changing state, history, ordering, or SQL behavior.
- `src/Domain/MigrationManager.php` owns forward/rollback ordering and target resolution.
- `src/Application/MigrationLifecycle.php` orders SQL, current-state, and history writes.
- `src/Infrastructure/MigrationTracker.php` and `WordPressSqlExecutor.php` are the database boundary.

## Verification

- Fast behavior check: `composer tests`.
- Full required check: `composer qa`.
- Real database check: `composer tests:database` with the `WORDPRESS_DB_*`
  environment variables pointing to a disposable MariaDB database.
- State/order changes need a `MigrationManagerTest`; SQL routing changes need a `WordPressSqlExecutorTest`.

## Invariants

- Apply migrations in registration order and roll them back in reverse order; stop on the first failure.
- Execute SQL before changing current state, then append history only after the state write succeeds.
- `mark_up` and `mark_down` change metadata only and never execute migration SQL.
- Route `CREATE TABLE` through `dbDelta()`; route all other statements through `$wpdb->query()`.
- State and history are separate tables. Automatic cleanup remains opt-in and must not erase history unexpectedly.

## Cross-repository impact and done

- The kernel discovers `MigrationBundle` and `migration/migration.php` through `extra.kernel`; WP-CLI exposes the same manager operations.
- Migration/metadata changes can alter production data. Do not claim real-database verification when only the test `wpdb` double ran.
- A change is done when the relevant sequence/SQL test and `composer qa` pass,
  database-boundary changes also pass `composer tests:database`, and
  `docs/architecture.md` still matches the implementation.

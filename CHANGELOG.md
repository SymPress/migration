# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## Unreleased

- Continue other plugins after a failed migration, then return a failing aggregate CLI result.
- Add administrator-only `wp migration adopt` for an explicitly confirmed exact legacy identity/version; retain the recorded version and append-only history without migration SQL.
- Run real database contracts against pinned MySQL 8.4 and MariaDB 11.8 services.

## 1.0.6 — 2026-10-02

- Document the two narrowly scoped WordPress Plugin Check exceptions for the private temporary CSV-formatting stream; WordPress file-operation checks remain enabled elsewhere.

## 1.0.5 — 2026-10-02

- Grant the archive caller the permissions required by the pinned reusable workflow, with artifact attestations still explicitly disabled.
- Exclude development tests, tooling and documentation from the WordPress archive.

## 1.0.4 — 2026-10-02

- Resolve deferred schema SQL under the advisory lock, recheck applied state before planning, and release the lock on planning failures.
- Add the backward-compatible deferred-operation executor contract used by ORM schema migrations.

## 1.0.3 — 2026-10-02

### Fixed

- Block WP-CLI database rollbacks and backward migration targets outside loaded
  WordPress local/development environments, including missing environment APIs.
- Enforce forward-only target execution inside the manager while preserving the
  default library rollback API for explicitly reviewed inverse migrations.
- Correct WP-CLI execution flag synopsis so `--up` and `--down` are recognized;
  the executor continues to require exactly one direction.

## Unreleased

### Changed

- Split WP-CLI migration command execution and reporting into focused collaborators.
- Adopt shared SymPress QA tooling for package scripts and development dependencies.

### Fixed

- Cover rollback behavior when a migration fails during reverse execution.

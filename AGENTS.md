# Agents Guide

This file is a discoverability shortcut for AI coding agents (Claude Code, Cursor, Codex, Aider, …). The canonical guide lives in the docs:

→ **[docs/contributing/agents/working-with-agents.md](docs/contributing/agents/working-with-agents.md)** — repo layout, bootstrap order, testing, commit conventions, working style.

For Git workflow and commit conventions, see **[docs/contributing/how-to-contribute.md](docs/contributing/how-to-contribute.md)**.

## Quickstart

```bash
# Bring up the dockerized e2e stack
cd tests/e2e && npm run start

# Run the full e2e suite (~6 min, 590+ tests)
npm run tests

# Run one spec
npm run tests -- --spec 'tests/cypress/e2e/core/<name>.cy.js'
```

App is at `http://localhost:8080` (NOT `:4000`).

Feature PRs target `develop` (promoted to `main` separately) — not `main`. Use atomic conventional commits (`refactor(...)`, `feat(...)`, `test(...)`, `docs(...)` — one scope per commit, one-line message, no `Co-Authored-By` trailer).

## Render engine: Katana (Blade)

The view renderer is the [Katana](https://github.com/katanaphp/blade) Blade engine (`.blade.php`),
Blade only with no closure fallback. Legacy `return[...]` views are migrated to Blade by
version-migrator v1.3; framework-bundled views ship migrated in-repo.

- `src/Rendering/Katana/Engine.php` is the only Katana glue: an ordered userspace-then-framework
  finder chain, a fresh `Blade` per render, and the framework essentials registered under the
  `zubzet::` component namespace. `src/Rendering/Katana/Hooks.php` binds `@auth` / `@guest`.
- `src/Support/Helpers.php` delegates `e()` to `\Blade\e()`.
- See [Views](docs/core-features/views.md) for the templating reference and
  [Working With Agents](docs/contributing/agents/working-with-agents.md#render-engine-katana) for the
  adapter internals.

## Work in progress: database cluster resilience (issue #80)

`Connection::exec()` in `src/Database/Connection.php` recovers two failure
classes before surfacing them, sharing the `db_max_retries` budget (default
`3`, `0` disables):

- **Transient contention** (deadlock `1213`, lock-wait timeout `1205`, Galera
  serialization `40001`): the server already discarded the statement; the
  still-valid prepared statement is re-executed after a randomized 10-50 ms
  backoff.
- **Connection loss** (`1047`, `2002`, `2003`, `2006`, `2013`: a node died,
  the mesh moved the endpoint, or a Galera node refuses service while
  desynced as an SST/IST donor): reconnect through the configured endpoint,
  re-prepare, re-run, with attempt-scaled backoff (400 ms steps, capped at
  2 s) spanning a realistic failover window. Documented caveat: if a write
  was applied but its acknowledgment was lost, the re-run applies it again;
  every `exec()` is auto-committed, so the exposure is one statement. For
  `1047` the refusing node never executed the statement, so that re-run is
  always safe; with `wsrep_sync_wait` enabled, donors answer reads with
  `1047` during every node rejoin, making this classification essential.
  `connect()` bounds each attempt with a 5 s connect timeout and normalizes
  the PHP 8.0 false-return into the same exception the 8.1+ path throws.

Operational contract, learned the hard way: a MySQL client waiting for the
server greeting has **no read timeout**, so the database endpoint (mesh,
haproxy) must close sessions to dead backends quickly; the e2e proxy config
(`tests/e2e/packaging/docker/haproxy-database.cfg`) shows the fail-fast
settings. Caller-managed raw-SQL transactions are not retried at statement
level and stay the caller's responsibility; `executeMultiQuery()` has no
retries on purpose.

**The e2e suite runs on a real three-node Galera cluster** (MariaDB 11.8,
settings mirroring the production nixops definition) behind an haproxy that
plays the production service mesh: single endpoint `database:3306`,
round-robin across all nodes (multi-writer, like production), sessions shut
on node death. `wsrep_sync_wait = 1` is an e2e-only addition for
deterministic cross-node read-your-writes; production does not set it.
`galera1` bootstraps only on first initialization and rejoins on restart
(see the compose command wrapper); the image's `healthcheck.sh` is unusable
on joiners because SST replaces the datadir, so the healthcheck asserts
`wsrep_ready` instead. Every existing spec therefore exercises Galera
implicitly; dedicated coverage lives in
`tests/cypress/e2e/database/{cluster,failover,retry}.cy.js` (membership,
replication to every node, a request surviving a mid-flight kill of the
exact node it landed on, and the two lock-conflict semantics: same-node
lock-wait retries and cross-node certification, where Galera row locks do
not span nodes).

**Open decisions for review:** retries default-on (current) vs opt-in;
retry logging/metrics (skipped so far to avoid the slow-query logger's
checkpoint reentrancy); multi-host `dbhost` lists as a later, additive
feature for infrastructures without a mesh. Unit tests for `isRetryable()`
are tracked in issue #180.

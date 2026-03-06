# AGENTS.md

This file provides guidance to AI coding agents working in this repository.

## Project

Native Synology DSM 7.0+ package for the Transmission BitTorrent client. Builds a `.spk` package with ExtJS 4.x frontend, PHP backend, and Transmission RPC integration.

## Architecture

```
Frontend (ExtJS 4.x)  →  SYNO.WebAPI  →  PHP Backend  →  Transmission RPC (localhost:9091)
                                              ↓
                                         SQLite DB (user ownership, RSS, automation)
```

- **UI layer** (`ui/modules/*.js`): ExtJS components extending `SYNO.SDS.*` and `SYNO.ux.*` classes. Main entry is `MainWindow.js` which composes `TorrentGrid`, `DetailPanel`, sidebar, and toolbar.
- **API layer** (`webapi/*.lib`): DSM WebAPI definitions mapping method names to permission rules. Each `.lib` file declares allowed users/groups per method.
- **Backend** (`webapi/src/*.php`): PHP classes handling business logic. `TorrentManager.php` is the primary orchestrator; `TransmissionRPC.php` handles daemon communication; `Database.php` wraps SQLite.
- **Package scripts** (`package/scripts/*`): Bash scripts for install/uninstall/start/stop lifecycle.
- **Schema** (`schema.sql`): SQLite tables for user-torrent associations, RSS feeds/filters/history, and automation rules.

## Development Workflow

### Agent Roles
- **Developer**: Implements features, writes code, creates PRs
- **Tester**: Runs tests, validates with Playwright (when UI exists), reports issues
- **Consultant**: Reviews architecture, adversarial plan review, code review

### Rules
1. **One PR at a time** — never open a second PR while one is active
2. **When picking up work**, comment on the issue with a thread noting what you're doing
3. **When closing**, comment with results
4. **PRs use squash+merge** only when:
   - CI is green
   - No unresolved code review comments
   - Playwright is satisfied (only gated when UI changes exist)
5. **All Codex bot reviews are blockers** on PRs
6. **Plan adversarially** — plans are reviewed by Codex with max 5 reconciliation rounds
7. **Plan hard, implement easy** — prefer 10x planning over 10x code edits

### Build

```bash
./build.sh              # Produces build/TransmissionManager-<version>.spk
```

### Lint

```bash
# PHP
php -l webapi/src/*.php

# JavaScript (when eslint is configured)
npx eslint ui/modules/
```

### Tests

```bash
# PHP unit tests
./vendor/bin/phpunit tests/

# Single test
./vendor/bin/phpunit tests/TransmissionRPCTest.php

# Playwright E2E (requires running DSM instance, see AGENTS-local.md)
npx playwright test

# Single Playwright test
npx playwright test tests/e2e/torrent-grid.spec.ts
```

## Key Conventions

- **Translations**: Use `_T('section', 'key')` — never hardcode user-visible strings. Translation files in `ui/texts/<lang>/strings`.
- **API methods**: Defined in `webapi/SYNO.Transmission.<Module>.lib` files. Each method must declare permissions.
- **User isolation**: Every torrent operation must verify ownership via `Database::getUserTorrents()`. Never expose cross-user data.
- **Path validation**: Download paths must be validated against allowed directories before passing to Transmission RPC.
- **DSM compatibility**: Target DSM 7.0+ (os_min_ver: 7.0-40000). Use only ExtJS 4.x APIs available in DSM's bundled version.
- **RPC session handling**: Transmission uses CSRF via `X-Transmission-Session-Id` header. The RPC client must handle 409 responses by extracting and retrying with the session ID.

## Local Testing

For Playwright and integration testing against a real Synology NAS, create `AGENTS-local.md` (gitignored) with:

```markdown
## Synology Connection Details
- **Host**: <DSM IP or hostname>
- **Port**: <DSM port, typically 5000 or 5001 for HTTPS>
- **Username**: <test user>
- **Password**: <test password>
- **Transmission RPC Port**: 9091
- **Download Path**: /volume1/downloads
```

This file is gitignored and must never be committed.

## File Naming

- PHP classes: `PascalCase.php` (e.g., `TorrentManager.php`)
- JS modules: `PascalCase.js` (e.g., `MainWindow.js`)
- API libs: `SYNO.Transmission.<Module>.lib`
- Package scripts: lowercase, no extension (e.g., `postinst`)
- SQL: `schema.sql` at project root

## SPK Package Structure

The `.spk` file is a tarball containing `package.tgz` (which itself contains `package/`, `ui/`, `webapi/`). The `INFO` file in `package/` defines metadata, dependencies, and DSM desktop registration (`dsmappname`, `dsmuidir`).

# Developer Guide

This guide covers everything needed to build, test, and contribute to SynoTransmissionManager.

## Prerequisites

| Tool | Version | Purpose |
|------|---------|---------|
| PHP | 7.4+ | Backend runtime (matches DSM 7.x bundled PHP) |
| Node.js | 20+ | JavaScript linting and Playwright E2E tests |
| Composer | 2.x | PHP dependency management (PHPUnit, etc.) |
| Bash | 4+ | Build and helper scripts |

Install PHP dependencies:

```bash
composer install
```

Install Node.js dependencies:

```bash
npm ci
```

## Project Structure

```
SynoTransmissionManager/
├── ui/                        # Frontend (ExtJS 4.x)
│   ├── modules/               # ExtJS components (MainWindow, TorrentGrid, etc.)
│   ├── config/                # ExtJS application config
│   ├── texts/                 # i18n translation files
│   │   ├── enu/strings        # English (primary)
│   │   ├── rum/strings        # Romanian
│   │   ├── fre/strings        # French
│   │   └── ger/strings        # German
│   ├── styles/                # CSS stylesheets
│   ├── images/                # Icons and image assets
│   └── index.php              # UI entry point
├── webapi/                    # Backend
│   ├── src/                   # PHP source files
│   │   ├── entry.php          # WebAPI dispatcher / router
│   │   ├── TorrentManager.php # Core orchestrator
│   │   ├── TransmissionRPC.php# Transmission daemon RPC client
│   │   ├── Database.php       # SQLite wrapper
│   │   ├── RSSManager.php     # RSS feed/filter management
│   │   ├── AutomationEngine.php # Trigger-condition-action rules
│   │   ├── NotificationService.php # DSM Notification Center
│   │   ├── RateLimiter.php    # Per-user rate limiting
│   │   ├── rss-processor.php  # CLI: RSS feed checker (cron)
│   │   ├── automation-processor.php # CLI: automation rule evaluator (cron)
│   │   └── torrent-handler.php# File Station context-menu handler
│   ├── SYNO.Transmission.Torrent.lib     # Torrent API definition
│   ├── SYNO.Transmission.Settings.lib    # Settings API definition
│   ├── SYNO.Transmission.RSS.lib         # RSS API definition
│   └── SYNO.Transmission.Automation.lib  # Automation API definition
├── package/                   # SPK package metadata
│   ├── INFO                   # Package name, version, DSM requirements
│   ├── conf/                  # DSM privilege config
│   └── scripts/               # Lifecycle scripts (preinst, postinst, etc.)
├── tests/                     # Test suites
│   ├── *Test.php              # PHPUnit unit tests
│   ├── Support/               # Test helpers and testable subclasses
│   └── e2e/                   # Playwright E2E tests
├── scripts/                   # Developer helper scripts
│   ├── dev.sh                 # rsync to NAS for rapid iteration
│   └── bump-version.sh        # Update version in package/INFO
├── .github/                   # GitHub configuration
│   ├── workflows/ci.yml       # CI pipeline (lint, test, build, E2E)
│   ├── workflows/release.yml  # Automated release on tag push
│   ├── ISSUE_TEMPLATE/        # Bug report and feature request templates
│   └── pull_request_template.md
├── schema.sql                 # SQLite database schema
├── build.sh                   # SPK package builder
├── phpunit.xml                # PHPUnit configuration
├── composer.json               # PHP dependencies
├── package.json               # Node.js dependencies
├── playwright.config.ts       # Playwright configuration
└── .eslintrc.json             # ESLint configuration
```

## Architecture Overview

```
┌─────────────────┐     ┌─────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  ExtJS Frontend │────>│ SYNO.WebAPI  │────>│   PHP Backend    │────>│ Transmission RPC  │
│  (ui/modules/)  │     │  (.lib defs) │     │  (webapi/src/)   │     │  (localhost:9091) │
└─────────────────┘     └─────────────┘     └──────────────────┘     └──────────────────┘
                                                     │
                                                     v
                                              ┌──────────────┐
                                              │   SQLite DB   │
                                              │ (user ownership│
                                              │  RSS, rules)  │
                                              └──────────────┘
```

**Request flow:**

1. The ExtJS frontend makes AJAX calls to DSM's WebAPI proxy endpoint.
2. DSM authenticates the user, injects `HTTP_X_SYNO_USER`, and routes the call to `webapi/src/entry.php` based on the `.lib` definitions.
3. `entry.php` dispatches to the appropriate handler (`handleTorrentApi`, `handleSettingsApi`, `handleRSSApi`, `handleAutomationApi`).
4. Handlers use `TorrentManager`, `RSSManager`, or `AutomationEngine` which communicate with the Transmission daemon via `TransmissionRPC` and persist ownership/metadata in SQLite via `Database`.

**Key design decisions:**

- **Per-user isolation**: Every torrent operation checks ownership against the `user_torrents` table. Users can only see and control their own torrents.
- **Path validation**: Download directories are validated against an allow-list to prevent directory-traversal attacks.
- **CSRF handling**: `TransmissionRPC` handles Transmission's `X-Transmission-Session-Id` CSRF mechanism by catching 409 responses and retrying.
- **Rate limiting**: The `RateLimiter` enforces per-user, per-action limits stored in SQLite.

## Building

Build the `.spk` package:

```bash
./build.sh
```

This produces `build/TransmissionManager-<version>.spk`. The script:

1. Validates all required files exist
2. Copies `package/`, `ui/`, `webapi/`, `etc/`, and `schema.sql` into a staging directory
3. Strips dev-only files (`.gitkeep`, `.md`, `.DS_Store`)
4. Creates `package.tgz` (inner tarball) and wraps it with `INFO` into the final `.spk`

## Development Iteration

For rapid development against a real Synology NAS, use the dev sync script:

```bash
./scripts/dev.sh <nas-host> [nas-user]
```

Example:

```bash
./scripts/dev.sh 192.168.1.100 root
```

This rsyncs `ui/`, `webapi/src/`, `webapi/*.lib`, `schema.sql`, and `package/scripts/` to the NAS, then restarts the package. The package must be installed on the NAS first via the `.spk`.

**Prerequisites for dev sync:**

- SSH access to the NAS (key-based auth recommended)
- rsync installed on both host and NAS
- The package must already be installed once via `.spk`

## Testing

### PHPUnit (unit tests)

Run all tests:

```bash
./vendor/bin/phpunit
```

Run a single test file:

```bash
./vendor/bin/phpunit tests/TransmissionRPCTest.php
```

Run with coverage:

```bash
./vendor/bin/phpunit --coverage-clover build/coverage/clover.xml
```

The test suite includes 211+ tests with 400+ assertions covering:
- `TransmissionRPCTest` - RPC client, session handling, CSRF retry
- `TorrentManagerTest` - Ownership verification, path validation, CRUD
- `DatabaseTest` - SQLite operations, user-torrent associations
- `RSSManagerTest` - Feed/filter CRUD, pattern matching
- `AutomationEngineTest` - Rule evaluation, trigger matching
- `NotificationServiceTest` - DSM notification integration
- `RateLimiterTest` - Rate limit enforcement and cleanup

### JavaScript Linting

```bash
npx eslint ui/modules/ ui/config/
```

### PHP Linting

```bash
php -l webapi/src/*.php
```

### Playwright E2E

Playwright tests require a running DSM instance. Configure connection details in `AGENTS-local.md` (gitignored).

```bash
# Install browsers (first time only)
npx playwright install --with-deps

# Run all E2E tests
npx playwright test

# Run a specific test
npx playwright test tests/e2e/torrent-grid.spec.ts
```

## Adding Translations

Translation files live in `ui/texts/<lang>/strings` where `<lang>` is a Synology language code:

| Code | Language |
|------|----------|
| `enu` | English |
| `rum` | Romanian |
| `fre` | French |
| `ger` | German |

### Translation file format

Each `strings` file uses an INI-like section/key format:

```ini
[section]
key = "Value"
```

Example from `ui/texts/enu/strings`:

```ini
[torrent]
add_torrent = "Add Torrent"
name = "Name"
size = "Size"
```

### Using translations in JavaScript

Use the `_T()` function to reference translated strings:

```javascript
_T('torrent', 'add_torrent')  // Returns "Add Torrent" (or localized equivalent)
_T('common', 'ok')            // Returns "OK"
```

**Rules:**
- Never hardcode user-visible strings; always use `_T('section', 'key')`.
- When adding a new string, add it to all four language files.
- The English (`enu`) file is the primary reference.

## Code Conventions

### PHP

- Always declare `declare(strict_types=1);` at the top of every PHP file.
- Use prepared statements for all database queries (never concatenate user input into SQL).
- No PHP namespaces in `webapi/src/` (DSM's autoloading does not support them).
- Class names use PascalCase: `TorrentManager.php`, `TransmissionRPC.php`.
- All public methods must have PHPDoc blocks with `@param` and `@return` annotations.
- Validate all download paths against the allowed-paths list before passing to Transmission.

### JavaScript

- ES5 only (no arrow functions, no `let`/`const`, no template literals). DSM's bundled ExtJS 4.x runs in a legacy JS environment.
- Extend `SYNO.SDS.*` and `SYNO.ux.*` base classes for all UI components.
- Use `_T('section', 'key')` for all user-visible strings.
- Module files use PascalCase: `MainWindow.js`, `TorrentGrid.js`.

### API definitions

- Each API module has a `.lib` file: `webapi/SYNO.Transmission.<Module>.lib`.
- Every method must declare `allowUser` and `grantByDefault`.
- Admin-only methods set `grantByDefault: false` in the `.lib` and call `requireAdmin()` in the PHP handler.

### Database

- Schema is defined in `schema.sql` at the project root.
- Use the `Database` class for all SQLite operations.
- Foreign keys use `ON DELETE CASCADE` where appropriate.

## CI/CD Overview

### CI Pipeline (`.github/workflows/ci.yml`)

Triggered on push to `main` and on pull requests:

| Stage | Job | Description |
|-------|-----|-------------|
| 1 | **PHP Lint** | Syntax-checks all PHP files in `webapi/` and `tests/` |
| 2 | **JavaScript Lint** | Runs ESLint on `ui/modules/` and `ui/config/` |
| 3 | **PHP Tests** | Installs Composer dependencies, runs PHPUnit with coverage (depends on PHP Lint) |
| 4 | **Build SPK** | Runs `build.sh` to produce the `.spk` artifact (depends on all lint + test jobs) |
| 5 | **Playwright E2E** | Runs Playwright tests (conditional: only on PRs with `[UI]` in title or `ui` label; depends on Build SPK) |

### Release Pipeline (`.github/workflows/release.yml`)

Triggered on tag push matching `v*`:

1. Runs PHP Lint, JavaScript Lint, and PHP Tests
2. Builds the `.spk` package
3. Computes SHA256 checksums
4. Creates a GitHub Release with the `.spk` and `SHA256SUMS.txt` attached
5. Auto-generates release notes from commits

## Version Bumping

To update the version number:

```bash
./scripts/bump-version.sh <new-version>
```

Examples:

```bash
./scripts/bump-version.sh 1.0.0
./scripts/bump-version.sh 1.0.0-beta.1
./scripts/bump-version.sh 0.2.0-rc1
```

This updates the `version=` line in `package/INFO`. The build script reads the version from `package/INFO` to name the output `.spk` file.

After bumping, commit the change and tag it for release:

```bash
git add package/INFO
git commit -m "Bump version to <new-version>"
git tag v<new-version>
git push origin main --tags
```

Pushing the tag triggers the release workflow automatically.

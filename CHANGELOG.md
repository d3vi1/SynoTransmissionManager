# Changelog

All notable changes to SynoTransmissionManager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Core torrent management: add (file/URL/magnet), start, stop, remove with multi-select
- Real-time torrent grid with 2-second polling, progress bars, speed, ETA
- Category sidebar with status filters (All, Downloading, Seeding, Paused, Error) and label filters
- Detail panel with Files, Peers, Trackers, Info tabs and file priority management
- Comprehensive Settings panel: Connection, Speed Limits (with alt-speed scheduling), Download (ratio/idle limits), Peers (encryption, DHT/PEX/LPD/uTP, port forwarding)
- RSS feed manager: add/edit/delete feeds, filter editor with contains/regex/exact matching, exclusion patterns, download history
- Automation engine: trigger-condition-action rules for on-complete, on-add, on-ratio, scheduled events
- CLI processors for RSS feed checking and automation rule evaluation with lock files and log rotation
- DSM Notification Center integration with verbosity control (all/errors/none)
- File Station .torrent context menu integration
- Per-user torrent isolation (multi-user safe)
- Rate limiting: torrent_add 10/min, feed_add/rule_add 5/min, api_call 60/min
- Admin-only enforcement for settings mutations
- Input validation with InputValidator class
- QuickConnect compatible (all API calls via DSM proxy)
- Full i18n: English, Romanian, French, German
- Toast notifications, daemon-down banner, keyboard shortcuts (Del, Ctrl+A, Enter, Esc)
- Confirmation dialogs for destructive operations
- 211+ PHPUnit tests with 400+ assertions
- CI/CD pipeline with ESLint, PHP lint, PHPUnit, SPK build, Playwright E2E
- Automated release workflow on tag push

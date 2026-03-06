# Transmission Manager for Synology DSM

A native Synology DSM package for the Transmission BitTorrent client. Integrates fully with DSM's desktop environment using Synology's native UI framework (ExtJS-based), package system, and APIs. Users install via Package Center and access through DSM's desktop like any other native app (Download Station, File Station, etc).

## Core Principles

- **Native DSM Integration** — Uses SYNO.* APIs and DSM UI components
- **Package Format** — Standard `.spk` package installable via Package Center
- **DSM Authentication** — Integrates with DSM's user/permission system
- **Familiar UX** — Follows DSM design patterns (like Download Station)
- **Multi-user** — Each DSM user has isolated torrent management
- **Resource Efficient** — Runs on low-power NAS hardware

## Tech Stack

### Frontend (DSM UI Framework)
- **ExtJS 4.x** — DSM's native JavaScript framework
- **SYNO.SDS Namespace** — DSM desktop integration APIs
- **SYNO.ux Components** — Native DSM UI widgets
- **CSS** — DSM theme-compatible styling

### Backend
- **PHP 7.4+** — DSM's native backend language
- **Synology WebAPI** — Authentication, file access, notifications
- **Transmission RPC** — Communication with Transmission daemon
- **SQLite** — User preferences, RSS feeds, automation rules

### System Integration
- **DSM Package Format** (`.spk`)
- **Package Center** — Installation/updates
- **DSM Permissions** — User/group access control
- **Task Scheduler** — RSS monitoring, post-processing
- **Notification Center** — Download complete alerts
- **File Station** — File browser integration

## Features

### Torrent Management
- Add torrents via file upload, URL, or magnet link
- Start, stop, remove torrents with multi-select
- Real-time progress with 2-second polling
- Category sidebar filtering (All, Downloading, Seeding, Paused, Error)
- Full-text search across torrent names
- File priority management per-torrent
- Label/tag support

### RSS Automation
- RSS/Atom feed subscriptions with configurable refresh intervals
- Pattern-based filters (contains, regex, exact match)
- Exclude patterns to skip unwanted items
- Per-filter download paths and labels
- Download history to prevent duplicates

### Post-Processing & Automation
- Rule-based automation engine
- Trigger on download complete, ratio reached, or schedule
- Configurable actions (move files, notify, execute scripts)
- DSM Notification Center integration

### DSM Integration
- File Station right-click menu for `.torrent` files
- DSM Notification Center alerts
- QuickConnect remote access support
- Per-user torrent isolation with ownership verification
- Path validation restricting downloads to allowed directories

## Package Structure

```
TransmissionManager/
├── package/
│   ├── INFO                          # Package metadata
│   ├── PACKAGE_ICON.PNG              # 72x72 icon
│   ├── PACKAGE_ICON_256.PNG          # 256x256 icon
│   ├── scripts/
│   │   ├── start-stop-status         # Service control
│   │   ├── preinst                   # Pre-installation
│   │   ├── postinst                  # Post-installation
│   │   ├── preuninst                 # Pre-uninstall
│   │   └── postuninst                # Post-uninstall
│   └── conf/
│       ├── privilege                 # Permission requirements
│       └── resource                  # Resource configuration
├── ui/
│   ├── index.php                     # Entry point
│   ├── texts/
│   │   ├── enu/                      # English translations
│   │   ├── fre/                      # French
│   │   ├── ger/                      # German
│   │   └── rum/                      # Romanian
│   ├── modules/
│   │   ├── MainWindow.js             # Main application window
│   │   ├── TorrentGrid.js            # Torrent list grid
│   │   ├── DetailPanel.js            # Torrent details
│   │   ├── AddTorrentWindow.js       # Add torrent dialog
│   │   ├── RSSManager.js             # RSS feed manager
│   │   ├── AutomationManager.js      # Post-processing rules
│   │   └── SettingsPanel.js          # Application settings
│   ├── config/
│   │   └── config.js                 # UI configuration
│   ├── styles/
│   │   └── transmission.css          # Custom styles
│   └── images/
│       └── icons/                    # Application icons
├── webapi/
│   ├── SYNO.Transmission.Torrent.lib # Torrent operations API
│   ├── SYNO.Transmission.RSS.lib     # RSS management API
│   ├── SYNO.Transmission.Automation.lib # Automation API
│   ├── SYNO.Transmission.Settings.lib   # Settings API
│   └── src/
│       ├── TransmissionRPC.php       # RPC client
│       ├── TorrentManager.php        # Torrent operations
│       ├── RSSManager.php            # RSS feed handling
│       ├── AutomationEngine.php      # Post-processing
│       └── Database.php              # SQLite operations
├── etc/
│   └── transmission-settings.json    # Transmission daemon config
└── schema.sql                        # SQLite database schema
```

## Building

```bash
./build.sh
```

Produces `TransmissionManager-<version>.spk` in the `build/` directory.

## Requirements

- Synology DSM 7.0+ (os_min_ver: 7.0-40000)
- Transmission daemon (bundled or pre-installed)
- PHP 7.4+ (provided by DSM)
- Conflicts with Download Station (cannot run simultaneously)

## License

This project is licensed under the GNU Affero General Public License v3.0 — see [LICENSE](LICENSE) for details.

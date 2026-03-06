/**
 * MainWindow.js — DSM application shell for TransmissionManager.
 *
 * Extends SYNO.SDS.AppWindow with a border layout containing:
 *   - west:   CategorySidebar for label/status filtering
 *   - center: TorrentGrid with real-time polling
 *   - south:  DetailPanel with tabbed torrent details
 *
 * Toolbar provides Add, Start, Stop, Remove, RSS, Automation, Settings,
 * and search with a 300 ms debounce.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.MainWindow = Ext.extend(SYNO.SDS.AppWindow, {

    appInstance: null,
    searchTask: null,

    constructor: function (config) {
        var self = this;

        this.appInstance = config.appInstance;

        // Build child components
        this.categorySidebar = new SYNO.SDS.TransmissionManager.CategorySidebar({
            region: 'west',
            width: 200,
            split: true,
            collapsible: true,
            listeners: {
                filterchange: function (filter) {
                    self.torrentGrid.applyFilter(filter);
                }
            }
        });

        this.torrentGrid = new SYNO.SDS.TransmissionManager.TorrentGrid({
            region: 'center',
            appWindow: self,
            listeners: {
                selectionchange: function (sm) {
                    var records = sm.getSelections();
                    self.detailPanel.loadTorrent(records.length === 1 ? records[0] : null);
                    self.updateToolbar(records);
                }
            }
        });

        this.detailPanel = new SYNO.SDS.TransmissionManager.DetailPanel({
            region: 'south',
            height: 200,
            split: true,
            collapsible: true,
            collapsed: false
        });

        // Status bar
        this.statusBar = new Ext.Toolbar({
            cls: 'transmission-statusbar',
            items: [
                { xtype: 'tbtext', itemId: 'totalText', text: _T('common', 'search') + ': 0' },
                '->',
                { xtype: 'tbtext', itemId: 'speedText', text: '\u2193 0 B/s  \u2191 0 B/s' },
                '-',
                { xtype: 'tbtext', itemId: 'freeSpaceText', text: '' }
            ]
        });

        // Search field with 300ms debounce
        this.searchField = new Ext.form.TextField({
            emptyText: _T('common', 'search'),
            width: 200,
            enableKeyEvents: true,
            listeners: {
                keyup: function () {
                    if (self.searchTask) {
                        clearTimeout(self.searchTask);
                    }
                    self.searchTask = setTimeout(function () {
                        self.torrentGrid.applySearch(self.searchField.getValue());
                    }, 300);
                }
            }
        });

        var cfg = Ext.apply({
            title: 'Transmission Manager',
            width: 960,
            height: 600,
            minWidth: 720,
            minHeight: 400,
            layout: 'border',
            tbar: this.buildToolbar(),
            bbar: this.statusBar,
            items: [
                this.categorySidebar,
                this.torrentGrid,
                this.detailPanel
            ]
        }, config);

        SYNO.SDS.TransmissionManager.MainWindow.superclass.constructor.call(this, cfg);

        this.initEventHandlers();
    },

    /**
     * Wire up application-level event handlers.
     */
    initEventHandlers: function () {
        var self = this;
        var Util = SYNO.SDS.TransmissionManager.Util;

        // Add torrent — open AddTorrentWindow
        this.on('addtorrent', function () {
            var addWin = new SYNO.SDS.TransmissionManager.AddTorrentWindow({
                appWindow: self,
                listeners: {
                    torrentadded: function () {
                        Util.showToast(
                            _T('ui', 'success_add') || 'Torrent added successfully',
                            'success'
                        );
                        self.torrentGrid.loadTorrents();
                    }
                }
            });
            addWin.show();
        });

        // Settings — open SettingsPanel
        this.on('opensettings', function () {
            var settingsWin = new SYNO.SDS.TransmissionManager.SettingsPanel({
                appWindow: self
            });
            settingsWin.show();
        });

        // Start torrents
        this.on('starttorrents', function () {
            var selections = self.torrentGrid.getSelectionModel().getSelections();
            if (selections.length === 0) {
                return;
            }
            var ids = [];
            var i;
            for (i = 0; i < selections.length; i++) {
                ids.push(selections[i].get('id'));
            }
            SYNO.API.Request({
                api: 'SYNO.Transmission.Torrent',
                method: 'start',
                version: 1,
                params: { ids: ids },
                callback: function (success) {
                    if (success) {
                        Util.showToast(
                            _T('ui', 'success_start') || 'Torrent(s) started',
                            'success'
                        );
                        self.torrentGrid.loadTorrents();
                    } else {
                        Util.showError(_T('error', 'start_failed') || 'Failed to start torrent');
                    }
                }
            });
        });

        // Stop torrents
        this.on('stoptorrents', function () {
            var selections = self.torrentGrid.getSelectionModel().getSelections();
            if (selections.length === 0) {
                return;
            }
            var ids = [];
            var i;
            for (i = 0; i < selections.length; i++) {
                ids.push(selections[i].get('id'));
            }
            SYNO.API.Request({
                api: 'SYNO.Transmission.Torrent',
                method: 'stop',
                version: 1,
                params: { ids: ids },
                callback: function (success) {
                    if (success) {
                        Util.showToast(
                            _T('ui', 'success_stop') || 'Torrent(s) stopped',
                            'success'
                        );
                        self.torrentGrid.loadTorrents();
                    } else {
                        Util.showError(_T('error', 'stop_failed') || 'Failed to stop torrent');
                    }
                }
            });
        });

        // Remove torrents — with confirmation dialog
        this.on('removetorrents', function () {
            var selections = self.torrentGrid.getSelectionModel().getSelections();
            if (selections.length === 0) {
                return;
            }

            Ext.Msg.confirm(
                _T('remove', 'confirm_title') || 'Remove Torrent',
                _T('remove', 'confirm_message') || 'Are you sure you want to remove the selected torrent(s)?',
                function (btn) {
                    if (btn !== 'yes') {
                        return;
                    }
                    var ids = [];
                    var i;
                    for (i = 0; i < selections.length; i++) {
                        ids.push(selections[i].get('id'));
                    }
                    SYNO.API.Request({
                        api: 'SYNO.Transmission.Torrent',
                        method: 'remove',
                        version: 1,
                        params: { ids: ids },
                        callback: function (success) {
                            if (success) {
                                Util.showToast(
                                    _T('ui', 'success_remove') || 'Torrent(s) removed',
                                    'success'
                                );
                                self.torrentGrid.loadTorrents();
                            } else {
                                Util.showError(_T('error', 'remove_failed') || 'Failed to remove torrent');
                            }
                        }
                    });
                }
            );
        });

        // Open RSS Manager
        this.on('openrss', function () {
            var rssWin = new SYNO.SDS.TransmissionManager.RSSManager({
                appWindow: self
            });
            rssWin.show();
        });

        // Open Automation Manager
        this.on('openautomation', function () {
            var autoWin = new SYNO.SDS.TransmissionManager.AutomationManager({
                appWindow: self
            });
            autoWin.show();
        });
    },

    /**
     * Build the main toolbar.
     */
    buildToolbar: function () {
        var self = this;
        return new Ext.Toolbar({
            items: [
                {
                    text: _T('torrent', 'add_torrent'),
                    iconCls: 'syno-ux-icon-add',
                    itemId: 'btnAdd',
                    handler: function () {
                        self.fireEvent('addtorrent');
                    }
                },
                '-',
                {
                    text: _T('torrent', 'start'),
                    iconCls: 'syno-ux-icon-play',
                    itemId: 'btnStart',
                    disabled: true,
                    handler: function () {
                        self.fireEvent('starttorrents');
                    }
                },
                {
                    text: _T('torrent', 'pause'),
                    iconCls: 'syno-ux-icon-pause',
                    itemId: 'btnStop',
                    disabled: true,
                    handler: function () {
                        self.fireEvent('stoptorrents');
                    }
                },
                {
                    text: _T('torrent', 'remove'),
                    iconCls: 'syno-ux-icon-delete',
                    itemId: 'btnRemove',
                    disabled: true,
                    handler: function () {
                        self.fireEvent('removetorrents');
                    }
                },
                '-',
                {
                    text: _T('rss', 'title') || 'RSS',
                    iconCls: 'syno-ux-icon-rss',
                    itemId: 'btnRSS',
                    handler: function () {
                        self.fireEvent('openrss');
                    }
                },
                {
                    text: _T('automation', 'title') || 'Automation',
                    iconCls: 'syno-ux-icon-automation',
                    itemId: 'btnAutomation',
                    handler: function () {
                        self.fireEvent('openautomation');
                    }
                },
                '->',
                this.searchField,
                '-',
                {
                    text: _T('settings', 'title') || 'Settings',
                    iconCls: 'syno-ux-icon-config',
                    itemId: 'btnSettings',
                    handler: function () {
                        self.fireEvent('opensettings');
                    }
                }
            ]
        });
    },

    /**
     * Enable/disable toolbar buttons based on selection.
     *
     * @param {Ext.data.Record[]} records Selected torrent records
     */
    updateToolbar: function (records) {
        var tbar = this.getTopToolbar();
        var hasSelection = records && records.length > 0;

        var btnStart = tbar.getComponent('btnStart');
        var btnStop = tbar.getComponent('btnStop');
        var btnRemove = tbar.getComponent('btnRemove');

        if (btnStart) {
            btnStart.setDisabled(!hasSelection);
        }
        if (btnStop) {
            btnStop.setDisabled(!hasSelection);
        }
        if (btnRemove) {
            btnRemove.setDisabled(!hasSelection);
        }
    },

    /**
     * Update the status bar with aggregate stats.
     *
     * @param {Object} stats { total, downloading, seeding, paused, downSpeed, upSpeed, freeSpace }
     */
    updateStatusBar: function (stats) {
        var bbar = this.getBottomToolbar();
        if (!bbar) {
            return;
        }

        var totalText = bbar.getComponent('totalText');
        var speedText = bbar.getComponent('speedText');
        var freeSpaceText = bbar.getComponent('freeSpaceText');

        if (totalText) {
            totalText.setText(
                _T('category', 'all') + ': ' + (stats.total || 0) +
                '  \u2193 ' + (stats.downloading || 0) +
                '  \u2191 ' + (stats.seeding || 0)
            );
        }
        if (speedText) {
            speedText.setText(
                '\u2193 ' + SYNO.SDS.TransmissionManager.Util.formatSpeed(stats.downSpeed || 0) +
                '  \u2191 ' + SYNO.SDS.TransmissionManager.Util.formatSpeed(stats.upSpeed || 0)
            );
        }
        if (freeSpaceText && stats.freeSpace !== undefined) {
            freeSpaceText.setText(
                SYNO.SDS.TransmissionManager.Util.formatSize(stats.freeSpace)
            );
        }
    },

    onDestroy: function () {
        if (this.searchTask) {
            clearTimeout(this.searchTask);
        }
        if (this.torrentGrid) {
            this.torrentGrid.stopPolling();
        }
        SYNO.SDS.TransmissionManager.MainWindow.superclass.onDestroy.call(this);
    }
});

/**
 * DSM Application Instance — the launcher hook.
 */
SYNO.SDS.TransmissionManager.Application = Ext.extend(SYNO.SDS.AppInstance, {

    appWindowName: 'SYNO.SDS.TransmissionManager.MainWindow',
    mainWindow: null,

    onStart: function () {
        this.mainWindow = this.createMainWindow();
    },

    createMainWindow: function () {
        return new SYNO.SDS.TransmissionManager.MainWindow({
            appInstance: this
        });
    },

    onStop: function () {
        if (this.mainWindow) {
            this.mainWindow.destroy();
            this.mainWindow = null;
        }
    }
});

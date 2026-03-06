/**
 * TorrentGrid.js — Main data grid showing the user's torrents.
 *
 * Polls SYNO.Transmission.Torrent.list on a configurable interval
 * (default 2 s). Supports search filtering and category filtering.
 * Renders progress bars, speed, ETA, and status via custom renderers.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.TorrentGrid = Ext.extend(Ext.grid.GridPanel, {

    appWindow: null,
    pollTimer: null,
    currentFilter: null,
    currentSearch: '',
    _initialLoadDone: false,

    constructor: function (config) {
        this.appWindow = config.appWindow || null;

        var store = new Ext.data.JsonStore({
            idProperty: 'id',
            fields: [
                { name: 'id', type: 'int' },
                { name: 'hashString', type: 'string' },
                { name: 'name', type: 'string' },
                { name: 'status', type: 'int' },
                { name: 'error', type: 'int' },
                { name: 'errorString', type: 'string' },
                { name: 'totalSize', type: 'float' },
                { name: 'percentDone', type: 'float' },
                { name: 'rateDownload', type: 'int' },
                { name: 'rateUpload', type: 'int' },
                { name: 'uploadRatio', type: 'float' },
                { name: 'eta', type: 'int' },
                { name: 'peersConnected', type: 'int' },
                { name: 'uploadedEver', type: 'float' },
                { name: 'downloadedEver', type: 'float' },
                { name: 'addedDate', type: 'int' },
                { name: 'doneDate', type: 'int' },
                { name: 'labels' }
            ],
            data: []
        });

        var emptyMsg = (_T('ui', 'no_torrents') || 'No torrents yet') + '. ' +
            (_T('ui', 'add_first') || 'Click Add Torrent to get started.');

        var cfg = Ext.apply({
            store: store,
            loadMask: true,
            stripeRows: true,
            columnLines: true,
            sm: new Ext.grid.RowSelectionModel({ singleSelect: false }),
            columns: [
                {
                    header: _T('torrent', 'name'),
                    dataIndex: 'name',
                    flex: 1,
                    sortable: true,
                    renderer: this.renderName
                },
                {
                    header: _T('torrent', 'size'),
                    dataIndex: 'totalSize',
                    width: 80,
                    sortable: true,
                    renderer: SYNO.SDS.TransmissionManager.Util.formatSize
                },
                {
                    header: _T('torrent', 'progress'),
                    dataIndex: 'percentDone',
                    width: 120,
                    sortable: true,
                    renderer: this.renderProgress
                },
                {
                    header: _T('torrent', 'status'),
                    dataIndex: 'status',
                    width: 100,
                    sortable: true,
                    renderer: this.renderStatus
                },
                {
                    header: _T('torrent', 'speed_down'),
                    dataIndex: 'rateDownload',
                    width: 90,
                    sortable: true,
                    renderer: SYNO.SDS.TransmissionManager.Util.formatSpeed
                },
                {
                    header: _T('torrent', 'speed_up'),
                    dataIndex: 'rateUpload',
                    width: 90,
                    sortable: true,
                    renderer: SYNO.SDS.TransmissionManager.Util.formatSpeed
                },
                {
                    header: _T('torrent', 'eta'),
                    dataIndex: 'eta',
                    width: 80,
                    sortable: true,
                    renderer: SYNO.SDS.TransmissionManager.Util.formatEta
                },
                {
                    header: _T('torrent', 'ratio'),
                    dataIndex: 'uploadRatio',
                    width: 60,
                    sortable: true,
                    renderer: function (v) {
                        return v >= 0 ? v.toFixed(2) : '\u221E';
                    }
                }
            ],
            viewConfig: {
                forceFit: true,
                emptyText: emptyMsg
            },
            listeners: {
                afterrender: function (grid) {
                    grid.initKeyboardShortcuts();
                }
            }
        }, config);

        SYNO.SDS.TransmissionManager.TorrentGrid.superclass.constructor.call(this, cfg);
        this.startPolling();
    },

    // ---------------------------------------------------------------
    // Keyboard shortcuts
    // ---------------------------------------------------------------

    /**
     * Initialise keyboard shortcuts on the grid element.
     */
    initKeyboardShortcuts: function () {
        var self = this;
        var el = this.getEl();
        if (!el) {
            return;
        }

        new Ext.KeyMap(el, [
            {
                // Delete key — fire removetorrents event on appWindow
                key: Ext.EventObject.DELETE,
                fn: function () {
                    var selections = self.getSelectionModel().getSelections();
                    if (selections.length > 0 && self.appWindow) {
                        self.appWindow.fireEvent('removetorrents');
                    }
                }
            },
            {
                // Ctrl+A — select all rows
                key: 'a',
                ctrl: true,
                fn: function (keyCode, e) {
                    e.preventDefault();
                    self.getSelectionModel().selectAll();
                }
            },
            {
                // Enter — open detail if single selection
                key: Ext.EventObject.ENTER,
                fn: function () {
                    var selections = self.getSelectionModel().getSelections();
                    if (selections.length === 1 && self.appWindow && self.appWindow.detailPanel) {
                        self.appWindow.detailPanel.loadTorrent(selections[0]);
                        self.appWindow.detailPanel.expand(true);
                    }
                }
            },
            {
                // Escape — deselect all
                key: Ext.EventObject.ESC,
                fn: function () {
                    self.getSelectionModel().clearSelections();
                }
            }
        ]);
    },

    // ---------------------------------------------------------------
    // Polling
    // ---------------------------------------------------------------

    /**
     * Start periodic torrent list polling.
     */
    startPolling: function () {
        this.loadTorrents();
        var interval = SYNO.SDS.TransmissionManager.Config.POLL_INTERVAL || 2000;
        var self = this;
        this.pollTimer = setInterval(function () {
            self.loadTorrents();
        }, interval);
    },

    /**
     * Stop polling.
     */
    stopPolling: function () {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    },

    /**
     * Fetch torrents from the API and refresh the store.
     * Shows loadMask on initial load. Handles daemon-down state.
     */
    loadTorrents: function () {
        var self = this;
        var Util = SYNO.SDS.TransmissionManager.Util;

        // Show loadMask on initial load
        if (!this._initialLoadDone && this.el) {
            this.el.mask(_T('common', 'loading') || 'Loading...');
        }

        SYNO.API.Request({
            api: 'SYNO.Transmission.Torrent',
            method: 'list',
            version: 1,
            callback: function (success, response) {
                // Unmask after initial load
                if (!self._initialLoadDone && self.el) {
                    self.el.unmask();
                    self._initialLoadDone = true;
                }

                if (success && response && response.torrents) {
                    Util.hideDaemonDown();
                    self.updateStore(response.torrents);
                    self.updateStats(response.torrents);
                } else {
                    Util.showDaemonDown();
                }
            }
        });
    },

    /**
     * Update the grid store with new torrent data, preserving selection.
     *
     * @param {Object[]} torrents Raw torrent data from the API
     */
    updateStore: function (torrents) {
        var store = this.getStore();
        var sm = this.getSelectionModel();
        var selectedIds = {};
        var selections = sm.getSelections();
        var i;

        for (i = 0; i < selections.length; i++) {
            selectedIds[selections[i].get('id')] = true;
        }

        // Apply client-side filter + search
        var filtered = this.filterTorrents(torrents);

        store.loadData(filtered);

        // Restore selection
        var toSelect = [];
        store.each(function (record) {
            if (selectedIds[record.get('id')]) {
                toSelect.push(record);
            }
        });
        if (toSelect.length > 0) {
            sm.selectRecords(toSelect, false);
        }
    },

    /**
     * Filter torrents by current category and search text.
     *
     * @param {Object[]} torrents Raw torrent data
     * @return {Object[]} Filtered torrents
     */
    filterTorrents: function (torrents) {
        var filtered = torrents;
        var self = this;

        // Category/status filter
        if (this.currentFilter && this.currentFilter.type) {
            filtered = Ext.Array ? Ext.Array.filter(filtered, function (t) {
                return self.matchesFilter(t);
            }) : filtered.filter(function (t) {
                return self.matchesFilter(t);
            });
        }

        // Search filter
        if (this.currentSearch) {
            var search = this.currentSearch.toLowerCase();
            filtered = filtered.filter(function (t) {
                return (t.name || '').toLowerCase().indexOf(search) !== -1;
            });
        }

        return filtered;
    },

    /**
     * Check if a torrent matches the current category filter.
     *
     * @param {Object} torrent Torrent data
     * @return {boolean}
     */
    matchesFilter: function (torrent) {
        if (!this.currentFilter || !this.currentFilter.type) {
            return true;
        }

        var filter = this.currentFilter;

        switch (filter.type) {
            case 'status':
                return this.matchesStatusFilter(torrent, filter.value);
            case 'label':
                return torrent.labels && torrent.labels.indexOf(filter.value) !== -1;
            default:
                return true;
        }
    },

    /**
     * Check if a torrent matches a status filter.
     *
     * Transmission status codes:
     *   0=stopped, 1=check-wait, 2=checking, 3=download-wait,
     *   4=downloading, 5=seed-wait, 6=seeding
     */
    matchesStatusFilter: function (torrent, statusFilter) {
        switch (statusFilter) {
            case 'all':
                return true;
            case 'downloading':
                return torrent.status === 3 || torrent.status === 4;
            case 'seeding':
                return torrent.status === 5 || torrent.status === 6;
            case 'paused':
                return torrent.status === 0;
            case 'error':
                return torrent.error > 0;
            default:
                return true;
        }
    },

    /**
     * Apply a category/status filter from the sidebar.
     *
     * @param {Object} filter { type: 'status'|'label', value: string }
     */
    applyFilter: function (filter) {
        this.currentFilter = filter;
        this.loadTorrents();
    },

    /**
     * Apply a search filter from the toolbar.
     *
     * @param {string} searchText Search query
     */
    applySearch: function (searchText) {
        this.currentSearch = searchText || '';
        this.loadTorrents();
    },

    /**
     * Compute and push aggregate stats to the main window status bar.
     *
     * @param {Object[]} torrents All torrents (before filtering)
     */
    updateStats: function (torrents) {
        if (!this.appWindow) {
            return;
        }

        var stats = {
            total: torrents.length,
            downloading: 0,
            seeding: 0,
            paused: 0,
            downSpeed: 0,
            upSpeed: 0
        };

        for (var i = 0; i < torrents.length; i++) {
            var t = torrents[i];
            stats.downSpeed += t.rateDownload || 0;
            stats.upSpeed += t.rateUpload || 0;

            if (t.status === 4 || t.status === 3) {
                stats.downloading++;
            } else if (t.status === 6 || t.status === 5) {
                stats.seeding++;
            } else if (t.status === 0) {
                stats.paused++;
            }
        }

        this.appWindow.updateStatusBar(stats);
    },

    // ---------------------------------------------------------------
    // Renderers
    // ---------------------------------------------------------------

    /**
     * Render the torrent name, highlighting errors.
     */
    renderName: function (value, meta, record) {
        if (record.get('error') > 0) {
            meta.css = 'torrent-error';
            return '<span class="error-icon">\u26A0</span> ' + Ext.util.Format.htmlEncode(value);
        }
        return Ext.util.Format.htmlEncode(value);
    },

    /**
     * Render a progress bar.
     */
    renderProgress: function (value) {
        var pct = Math.round(value * 100);
        var colour = pct >= 100 ? '#22c55e' : '#3b82f6';
        return '<div class="progress-bar-wrap">' +
            '<div class="progress-bar" style="width:' + pct + '%;background-color:' + colour + ';"></div>' +
            '<div class="progress-text">' + pct + '%</div>' +
            '</div>';
    },

    /**
     * Render the torrent status as a translated string.
     */
    renderStatus: function (value) {
        var statusMap = {
            0: _T('status', 'stopped'),
            1: _T('status', 'check_wait'),
            2: _T('status', 'checking'),
            3: _T('status', 'download_wait'),
            4: _T('status', 'downloading'),
            5: _T('status', 'seed_wait'),
            6: _T('status', 'seeding')
        };
        return statusMap[value] || _T('status', 'unknown');
    },

    onDestroy: function () {
        this.stopPolling();
        SYNO.SDS.TransmissionManager.TorrentGrid.superclass.onDestroy.call(this);
    }
});

/**
 * RSSManager.js — Modal for managing RSS feed subscriptions and filters.
 *
 * Layout:
 *   Left:  Feed list with add/delete/refresh/enable toolbar
 *   Right: Tab panel with Filters and History tabs
 *
 * Feeds and filters are managed via SYNO.Transmission.RSS API.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.RSSManager = Ext.extend(SYNO.SDS.ModalWindow, {

    constructor: function (config) {
        // Feed list
        this.feedStore = new Ext.data.JsonStore({
            idProperty: 'id',
            fields: [
                { name: 'id', type: 'int' },
                { name: 'name', type: 'string' },
                { name: 'url', type: 'string' },
                { name: 'refresh_interval', type: 'int' },
                { name: 'is_enabled', type: 'int' },
                { name: 'last_checked', type: 'string' }
            ],
            data: []
        });

        this.feedGrid = new Ext.grid.GridPanel({
            region: 'west',
            width: 250,
            split: true,
            store: this.feedStore,
            columns: [
                { header: _T('rss', 'feed_name'), dataIndex: 'name', flex: 1 },
                {
                    header: _T('rss', 'enabled'),
                    dataIndex: 'is_enabled',
                    width: 50,
                    renderer: function (v) { return v ? '\u2713' : '\u2717'; }
                }
            ],
            sm: new Ext.grid.RowSelectionModel({ singleSelect: true }),
            tbar: [
                {
                    iconCls: 'syno-ux-icon-add',
                    tooltip: _T('rss', 'add_feed'),
                    handler: this.onAddFeed,
                    scope: this
                },
                {
                    iconCls: 'syno-ux-icon-delete',
                    tooltip: _T('rss', 'delete_feed'),
                    handler: this.onDeleteFeed,
                    scope: this
                },
                {
                    iconCls: 'syno-ux-icon-refresh',
                    tooltip: _T('rss', 'refresh'),
                    handler: this.loadFeeds,
                    scope: this
                }
            ],
            viewConfig: { forceFit: true }
        });

        // Filter store
        this.filterStore = new Ext.data.JsonStore({
            idProperty: 'id',
            fields: [
                { name: 'id', type: 'int' },
                { name: 'pattern', type: 'string' },
                { name: 'match_mode', type: 'string' },
                { name: 'exclude_pattern', type: 'string' },
                { name: 'download_path', type: 'string' },
                { name: 'labels', type: 'string' },
                { name: 'start_paused', type: 'int' }
            ],
            data: []
        });

        // Filter grid
        this.filterGrid = new Ext.grid.GridPanel({
            title: _T('rss', 'filters'),
            store: this.filterStore,
            columns: [
                { header: _T('rss', 'pattern'), dataIndex: 'pattern', flex: 1 },
                { header: _T('rss', 'match_mode'), dataIndex: 'match_mode', width: 80 },
                { header: _T('rss', 'exclude'), dataIndex: 'exclude_pattern', width: 100 }
            ],
            tbar: [
                {
                    iconCls: 'syno-ux-icon-add',
                    tooltip: _T('rss', 'add_filter'),
                    handler: this.onAddFilter,
                    scope: this
                },
                {
                    iconCls: 'syno-ux-icon-delete',
                    tooltip: _T('rss', 'delete_filter'),
                    handler: this.onDeleteFilter,
                    scope: this
                },
                '-',
                {
                    text: _T('rss', 'test_filter'),
                    handler: this.onTestFilter,
                    scope: this
                }
            ],
            viewConfig: { forceFit: true }
        });

        // History store
        this.historyStore = new Ext.data.JsonStore({
            fields: [
                { name: 'item_guid', type: 'string' },
                { name: 'downloaded_date', type: 'string' }
            ],
            data: []
        });

        this.historyGrid = new Ext.grid.GridPanel({
            title: _T('rss', 'history'),
            store: this.historyStore,
            columns: [
                { header: _T('rss', 'item'), dataIndex: 'item_guid', flex: 1 },
                { header: _T('rss', 'downloaded'), dataIndex: 'downloaded_date', width: 150 }
            ],
            viewConfig: { forceFit: true }
        });

        this.rightPanel = new Ext.TabPanel({
            region: 'center',
            activeTab: 0,
            items: [this.filterGrid, this.historyGrid]
        });

        var self = this;
        var cfg = Ext.apply({
            title: _T('rss', 'title'),
            width: 700,
            height: 500,
            layout: 'border',
            modal: true,
            items: [this.feedGrid, this.rightPanel],
            buttons: [
                {
                    text: _T('common', 'ok'),
                    handler: function () { self.close(); }
                }
            ]
        }, config);

        SYNO.SDS.TransmissionManager.RSSManager.superclass.constructor.call(this, cfg);

        // Wire feed selection
        this.feedGrid.getSelectionModel().on('rowselect', function (sm, idx, record) {
            self.loadFilters(record.get('id'));
            self.loadHistory(record.get('id'));
        });

        this.loadFeeds();
    },

    // ---------------------------------------------------------------
    // Data loading
    // ---------------------------------------------------------------

    loadFeeds: function () {
        var self = this;
        SYNO.API.Request({
            api: 'SYNO.Transmission.RSS',
            method: 'list_feeds',
            version: 1,
            callback: function (success, response) {
                if (success && response && response.feeds) {
                    self.feedStore.loadData(response.feeds);
                }
            }
        });
    },

    loadFilters: function (feedId) {
        var self = this;
        this.currentFeedId = feedId;
        SYNO.API.Request({
            api: 'SYNO.Transmission.RSS',
            method: 'list_filters',
            version: 1,
            params: { feed_id: feedId },
            callback: function (success, response) {
                if (success && response && response.filters) {
                    self.filterStore.loadData(response.filters);
                }
            }
        });
    },

    loadHistory: function (feedId) {
        var self = this;
        SYNO.API.Request({
            api: 'SYNO.Transmission.RSS',
            method: 'get_history',
            version: 1,
            params: { feed_id: feedId },
            callback: function (success, response) {
                if (success && response && response.history) {
                    self.historyStore.loadData(response.history);
                }
            }
        });
    },

    // ---------------------------------------------------------------
    // Feed CRUD
    // ---------------------------------------------------------------

    onAddFeed: function () {
        var self = this;
        Ext.Msg.prompt(_T('rss', 'add_feed'), _T('rss', 'feed_url'), function (btn, url) {
            if (btn === 'ok' && url) {
                Ext.Msg.prompt(_T('rss', 'add_feed'), _T('rss', 'feed_name'), function (btn2, name) {
                    if (btn2 === 'ok' && name) {
                        SYNO.API.Request({
                            api: 'SYNO.Transmission.RSS',
                            method: 'add_feed',
                            version: 1,
                            params: { name: name, url: url },
                            callback: function (success) {
                                if (success) {
                                    self.loadFeeds();
                                } else {
                                    SYNO.SDS.TransmissionManager.Util.showError(_T('error', 'add_failed'));
                                }
                            }
                        });
                    }
                });
            }
        });
    },

    onDeleteFeed: function () {
        var record = this.feedGrid.getSelectionModel().getSelected();
        if (!record) { return; }

        var self = this;
        Ext.Msg.confirm(_T('common', 'confirm'), _T('rss', 'confirm_delete_feed'), function (btn) {
            if (btn === 'yes') {
                SYNO.API.Request({
                    api: 'SYNO.Transmission.RSS',
                    method: 'delete_feed',
                    version: 1,
                    params: { feed_id: record.get('id') },
                    callback: function (success) {
                        if (success) {
                            self.loadFeeds();
                            self.filterStore.removeAll();
                            self.historyStore.removeAll();
                        }
                    }
                });
            }
        });
    },

    // ---------------------------------------------------------------
    // Filter CRUD
    // ---------------------------------------------------------------

    onAddFilter: function () {
        if (!this.currentFeedId) { return; }

        var self = this;
        Ext.Msg.prompt(_T('rss', 'add_filter'), _T('rss', 'pattern'), function (btn, pattern) {
            if (btn === 'ok' && pattern) {
                SYNO.API.Request({
                    api: 'SYNO.Transmission.RSS',
                    method: 'add_filter',
                    version: 1,
                    params: { feed_id: self.currentFeedId, pattern: pattern },
                    callback: function (success) {
                        if (success) {
                            self.loadFilters(self.currentFeedId);
                        }
                    }
                });
            }
        });
    },

    onDeleteFilter: function () {
        if (!this.currentFeedId) { return; }
        var record = this.filterGrid.getSelectionModel().getSelected();
        if (!record) { return; }

        var self = this;
        SYNO.API.Request({
            api: 'SYNO.Transmission.RSS',
            method: 'delete_filter',
            version: 1,
            params: { feed_id: self.currentFeedId, filter_id: record.get('id') },
            callback: function (success) {
                if (success) {
                    self.loadFilters(self.currentFeedId);
                }
            }
        });
    },

    onTestFilter: function () {
        var record = this.filterGrid.getSelectionModel().getSelected();
        if (!record) { return; }

        Ext.Msg.prompt(_T('rss', 'test_filter'), _T('rss', 'test_title'), function (btn, title) {
            if (btn === 'ok' && title) {
                SYNO.API.Request({
                    api: 'SYNO.Transmission.RSS',
                    method: 'test_filter',
                    version: 1,
                    params: {
                        title: title,
                        pattern: record.get('pattern'),
                        match_mode: record.get('match_mode'),
                        exclude_pattern: record.get('exclude_pattern')
                    },
                    callback: function (success, response) {
                        if (success && response) {
                            var msg = response.matches
                                ? _T('rss', 'filter_matches')
                                : _T('rss', 'filter_no_match');
                            Ext.Msg.alert(_T('rss', 'test_filter'), msg);
                        }
                    }
                });
            }
        });
    }
});

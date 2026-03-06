/**
 * DetailPanel.js — Tabbed detail view for a selected torrent.
 *
 * Tabs:
 *   - Files: tree of torrent files with checkboxes and priority combos
 *   - Peers: grid showing connected peers
 *   - Trackers: grid showing tracker URLs and status
 *   - Info: general torrent metadata
 *
 * Data is fetched via SYNO.Transmission.Torrent.get when a torrent
 * is selected in the main grid.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.DetailPanel = Ext.extend(Ext.TabPanel, {

    currentTorrentId: null,

    constructor: function (config) {
        // Files tab
        this.filesGrid = new Ext.grid.GridPanel({
            title: _T('detail', 'files') || 'Files',
            store: new Ext.data.JsonStore({
                fields: [
                    { name: 'index', type: 'int' },
                    { name: 'name', type: 'string' },
                    { name: 'length', type: 'float' },
                    { name: 'bytesCompleted', type: 'float' },
                    { name: 'wanted', type: 'boolean' },
                    { name: 'priority', type: 'int' }
                ],
                data: []
            }),
            columns: [
                {
                    header: _T('torrent', 'name'),
                    dataIndex: 'name',
                    flex: 1,
                    renderer: Ext.util.Format.htmlEncode
                },
                {
                    header: _T('torrent', 'size'),
                    dataIndex: 'length',
                    width: 80,
                    renderer: SYNO.SDS.TransmissionManager.Util.formatSize
                },
                {
                    header: _T('torrent', 'progress'),
                    width: 80,
                    renderer: function (v, meta, record) {
                        var total = record.get('length');
                        var done = record.get('bytesCompleted');
                        if (total <= 0) {
                            return '0%';
                        }
                        return Math.round((done / total) * 100) + '%';
                    }
                },
                {
                    header: _T('detail', 'priority') || 'Priority',
                    dataIndex: 'priority',
                    width: 80,
                    renderer: function (v) {
                        var map = { '-1': 'Low', 0: 'Normal', 1: 'High' };
                        return map[v] || 'Normal';
                    }
                }
            ],
            viewConfig: { forceFit: true }
        });

        // Peers tab
        this.peersGrid = new Ext.grid.GridPanel({
            title: _T('detail', 'peers') || 'Peers',
            store: new Ext.data.JsonStore({
                fields: [
                    { name: 'address', type: 'string' },
                    { name: 'clientName', type: 'string' },
                    { name: 'progress', type: 'float' },
                    { name: 'rateToClient', type: 'int' },
                    { name: 'rateToPeer', type: 'int' },
                    { name: 'flagStr', type: 'string' }
                ],
                data: []
            }),
            columns: [
                { header: _T('detail', 'address') || 'Address', dataIndex: 'address', width: 130 },
                { header: _T('detail', 'client') || 'Client', dataIndex: 'clientName', flex: 1 },
                {
                    header: _T('torrent', 'progress'),
                    dataIndex: 'progress',
                    width: 70,
                    renderer: function (v) { return Math.round(v * 100) + '%'; }
                },
                {
                    header: _T('torrent', 'speed_down'),
                    dataIndex: 'rateToClient',
                    width: 80,
                    renderer: SYNO.SDS.TransmissionManager.Util.formatSpeed
                },
                {
                    header: _T('torrent', 'speed_up'),
                    dataIndex: 'rateToPeer',
                    width: 80,
                    renderer: SYNO.SDS.TransmissionManager.Util.formatSpeed
                },
                { header: 'Flags', dataIndex: 'flagStr', width: 60 }
            ],
            viewConfig: { forceFit: true }
        });

        // Trackers tab
        this.trackersGrid = new Ext.grid.GridPanel({
            title: _T('detail', 'trackers') || 'Trackers',
            store: new Ext.data.JsonStore({
                fields: [
                    { name: 'announce', type: 'string' },
                    { name: 'lastAnnounceResult', type: 'string' },
                    { name: 'seederCount', type: 'int' },
                    { name: 'leecherCount', type: 'int' },
                    { name: 'lastAnnouncePeerCount', type: 'int' }
                ],
                data: []
            }),
            columns: [
                { header: 'URL', dataIndex: 'announce', flex: 1 },
                { header: _T('detail', 'status_result') || 'Status', dataIndex: 'lastAnnounceResult', width: 120 },
                { header: _T('detail', 'seeds') || 'Seeds', dataIndex: 'seederCount', width: 60 },
                { header: _T('detail', 'leechers') || 'Leechers', dataIndex: 'leecherCount', width: 60 },
                { header: _T('detail', 'peer_count') || 'Peers', dataIndex: 'lastAnnouncePeerCount', width: 60 }
            ],
            viewConfig: { forceFit: true }
        });

        // Info tab
        this.infoPanel = new Ext.Panel({
            title: _T('detail', 'info') || 'Info',
            bodyStyle: 'padding: 10px',
            autoScroll: true,
            html: ''
        });

        var cfg = Ext.apply({
            activeTab: 0,
            deferredRender: true,
            items: [
                this.filesGrid,
                this.peersGrid,
                this.trackersGrid,
                this.infoPanel
            ]
        }, config);

        SYNO.SDS.TransmissionManager.DetailPanel.superclass.constructor.call(this, cfg);
    },

    /**
     * Load detail data for a torrent, or clear the panel.
     *
     * @param {Ext.data.Record|null} record Selected torrent record, or null to clear
     */
    loadTorrent: function (record) {
        if (!record) {
            this.clearDetail();
            return;
        }

        var id = record.get('id');
        if (id === this.currentTorrentId) {
            return; // Already showing this torrent
        }

        this.currentTorrentId = id;
        var self = this;

        SYNO.API.Request({
            api: 'SYNO.Transmission.Torrent',
            method: 'get',
            version: 1,
            params: { id: id },
            callback: function (success, response) {
                if (success && response) {
                    self.populateDetail(response);
                }
            }
        });
    },

    /**
     * Populate all tabs with torrent detail data.
     *
     * @param {Object} data Full torrent detail from the API
     */
    populateDetail: function (data) {
        // Files tab
        if (data.files && data.fileStats) {
            var files = [];
            for (var i = 0; i < data.files.length; i++) {
                files.push({
                    index: i,
                    name: data.files[i].name,
                    length: data.files[i].length,
                    bytesCompleted: data.files[i].bytesCompleted,
                    wanted: data.fileStats[i] ? data.fileStats[i].wanted : true,
                    priority: data.fileStats[i] ? data.fileStats[i].priority : 0
                });
            }
            this.filesGrid.getStore().loadData(files);
        }

        // Peers tab
        if (data.peers) {
            this.peersGrid.getStore().loadData(data.peers);
        }

        // Trackers tab
        if (data.trackerStats) {
            this.trackersGrid.getStore().loadData(data.trackerStats);
        }

        // Info tab
        this.infoPanel.body.update(this.buildInfoHtml(data));
    },

    /**
     * Build the HTML content for the Info tab.
     *
     * @param {Object} data Torrent detail data
     * @return {string} HTML content
     */
    buildInfoHtml: function (data) {
        var Util = SYNO.SDS.TransmissionManager.Util;
        var rows = [
            ['Name', Ext.util.Format.htmlEncode(data.name || '')],
            ['Hash', Ext.util.Format.htmlEncode(data.hashString || '')],
            ['Size', Util.formatSize(data.totalSize || 0)],
            ['Downloaded', Util.formatSize(data.downloadedEver || 0)],
            ['Uploaded', Util.formatSize(data.uploadedEver || 0)],
            ['Ratio', data.uploadRatio >= 0 ? data.uploadRatio.toFixed(2) : '\u221E'],
            ['Location', Ext.util.Format.htmlEncode(data.downloadDir || '')],
            ['Pieces', (data.pieceCount || 0) + ' \u00D7 ' + Util.formatSize(data.pieceSize || 0)],
            ['Comment', Ext.util.Format.htmlEncode(data.comment || '')],
            ['Created by', Ext.util.Format.htmlEncode(data.creator || '')],
            ['Added', data.addedDate ? new Date(data.addedDate * 1000).toLocaleString() : '-'],
            ['Completed', data.doneDate > 0 ? new Date(data.doneDate * 1000).toLocaleString() : '-']
        ];

        var html = '<table class="transmission-info-table" cellpadding="4">';
        for (var i = 0; i < rows.length; i++) {
            html += '<tr><td style="font-weight:bold;width:120px">' + rows[i][0] + '</td>' +
                '<td>' + rows[i][1] + '</td></tr>';
        }
        html += '</table>';
        return html;
    },

    /**
     * Clear all detail tabs.
     */
    clearDetail: function () {
        this.currentTorrentId = null;
        this.filesGrid.getStore().removeAll();
        this.peersGrid.getStore().removeAll();
        this.trackersGrid.getStore().removeAll();
        this.infoPanel.body.update('');
    }
});

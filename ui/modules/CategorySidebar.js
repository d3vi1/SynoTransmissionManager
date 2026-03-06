/**
 * CategorySidebar.js — Side panel for filtering torrents by status or label.
 *
 * Displays a tree with:
 *   - Status filters (All, Downloading, Seeding, Paused, Error)
 *   - User labels (dynamically populated from torrent data)
 *
 * Fires a 'filterchange' event with { type, value } when the user
 * clicks a category node.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.CategorySidebar = Ext.extend(Ext.tree.TreePanel, {

    constructor: function (config) {
        this.addEvents('filterchange');

        var root = new Ext.tree.TreeNode({
            text: 'Root',
            expanded: true,
            children: []
        });

        // Status filters
        var statusNode = new Ext.tree.TreeNode({
            text: _T('category', 'all'),
            expanded: true,
            iconCls: 'syno-ux-icon-folder'
        });

        statusNode.appendChild(new Ext.tree.TreeNode({
            text: _T('category', 'all'),
            leaf: true,
            iconCls: 'syno-ux-icon-list',
            filterType: 'status',
            filterValue: 'all'
        }));
        statusNode.appendChild(new Ext.tree.TreeNode({
            text: _T('category', 'downloading'),
            leaf: true,
            iconCls: 'syno-ux-icon-download',
            filterType: 'status',
            filterValue: 'downloading'
        }));
        statusNode.appendChild(new Ext.tree.TreeNode({
            text: _T('category', 'seeding'),
            leaf: true,
            iconCls: 'syno-ux-icon-upload',
            filterType: 'status',
            filterValue: 'seeding'
        }));
        statusNode.appendChild(new Ext.tree.TreeNode({
            text: _T('category', 'paused'),
            leaf: true,
            iconCls: 'syno-ux-icon-pause',
            filterType: 'status',
            filterValue: 'paused'
        }));
        statusNode.appendChild(new Ext.tree.TreeNode({
            text: _T('category', 'error'),
            leaf: true,
            iconCls: 'syno-ux-icon-error',
            filterType: 'status',
            filterValue: 'error'
        }));

        root.appendChild(statusNode);

        // Labels container (populated dynamically)
        this.labelsNode = new Ext.tree.TreeNode({
            text: _T('torrent', 'labels') || 'Labels',
            expanded: true,
            iconCls: 'syno-ux-icon-tag'
        });
        root.appendChild(this.labelsNode);

        var self = this;

        var cfg = Ext.apply({
            title: _T('category', 'all'),
            rootVisible: false,
            root: root,
            useArrows: true,
            lines: false,
            autoScroll: true,
            listeners: {
                click: function (node) {
                    if (node.attributes.filterType) {
                        self.fireEvent('filterchange', {
                            type: node.attributes.filterType,
                            value: node.attributes.filterValue
                        });
                    }
                }
            }
        }, config);

        SYNO.SDS.TransmissionManager.CategorySidebar.superclass.constructor.call(this, cfg);
    },

    /**
     * Update the labels section with current labels from torrent data.
     *
     * @param {string[]} labels Unique labels extracted from all torrents
     */
    updateLabels: function (labels) {
        // Remove existing label children
        while (this.labelsNode.firstChild) {
            this.labelsNode.removeChild(this.labelsNode.firstChild);
        }

        // Add new label nodes
        for (var i = 0; i < labels.length; i++) {
            this.labelsNode.appendChild(new Ext.tree.TreeNode({
                text: labels[i],
                leaf: true,
                iconCls: 'syno-ux-icon-tag',
                filterType: 'label',
                filterValue: labels[i]
            }));
        }

        if (labels.length > 0) {
            this.labelsNode.expand();
        }
    }
});

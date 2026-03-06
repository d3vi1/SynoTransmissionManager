/**
 * AutomationManager.js — Modal for managing post-processing automation rules.
 *
 * Rule list grid with add/edit/delete/test. Each rule has a trigger type,
 * conditions, and actions managed via SYNO.Transmission.Automation API.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.AutomationManager = Ext.extend(SYNO.SDS.ModalWindow, {

    constructor: function (config) {
        this.ruleStore = new Ext.data.JsonStore({
            idProperty: 'id',
            fields: [
                { name: 'id', type: 'int' },
                { name: 'name', type: 'string' },
                { name: 'trigger_type', type: 'string' },
                { name: 'trigger_value', type: 'string' },
                { name: 'is_enabled', type: 'int' },
                { name: 'conditions' },
                { name: 'actions' }
            ],
            data: []
        });

        this.ruleGrid = new Ext.grid.GridPanel({
            region: 'center',
            store: this.ruleStore,
            columns: [
                { header: _T('automation', 'rule_name'), dataIndex: 'name', flex: 1 },
                {
                    header: _T('automation', 'trigger'),
                    dataIndex: 'trigger_type',
                    width: 100,
                    renderer: function (v) {
                        var map = {
                            'on-complete': _T('automation', 'on_complete'),
                            'on-add': _T('automation', 'on_add'),
                            'on-ratio': _T('automation', 'on_ratio'),
                            'schedule': _T('automation', 'schedule')
                        };
                        return map[v] || v;
                    }
                },
                {
                    header: _T('automation', 'actions_col'),
                    dataIndex: 'actions',
                    width: 150,
                    renderer: function (v) {
                        if (!v || !v.length) { return '-'; }
                        return v.map(function (a) { return a.type || '?'; }).join(', ');
                    }
                },
                {
                    header: _T('rss', 'enabled'),
                    dataIndex: 'is_enabled',
                    width: 60,
                    renderer: function (v) { return v ? '\u2713' : '\u2717'; }
                }
            ],
            tbar: [
                {
                    text: _T('automation', 'add_rule'),
                    iconCls: 'syno-ux-icon-add',
                    handler: this.onAddRule,
                    scope: this
                },
                {
                    text: _T('automation', 'delete_rule'),
                    iconCls: 'syno-ux-icon-delete',
                    handler: this.onDeleteRule,
                    scope: this
                },
                '-',
                {
                    text: _T('automation', 'test_rule'),
                    handler: this.onTestRule,
                    scope: this
                }
            ],
            viewConfig: { forceFit: true }
        });

        // Detail panel for selected rule
        this.detailPanel = new Ext.Panel({
            region: 'south',
            height: 150,
            split: true,
            bodyStyle: 'padding: 10px',
            autoScroll: true,
            html: ''
        });

        var self = this;
        var cfg = Ext.apply({
            title: _T('automation', 'title'),
            width: 650,
            height: 450,
            layout: 'border',
            modal: true,
            items: [this.ruleGrid, this.detailPanel],
            buttons: [
                {
                    text: _T('common', 'ok'),
                    handler: function () { self.close(); }
                }
            ]
        }, config);

        SYNO.SDS.TransmissionManager.AutomationManager.superclass.constructor.call(this, cfg);

        // Wire selection
        this.ruleGrid.getSelectionModel().on('rowselect', function (sm, idx, record) {
            self.showRuleDetail(record);
        });

        this.loadRules();
    },

    loadRules: function () {
        var self = this;
        SYNO.API.Request({
            api: 'SYNO.Transmission.Automation',
            method: 'list_rules',
            version: 1,
            callback: function (success, response) {
                if (success && response && response.rules) {
                    self.ruleStore.loadData(response.rules);
                }
            }
        });
    },

    showRuleDetail: function (record) {
        var conditions = record.get('conditions') || [];
        var actions = record.get('actions') || [];

        var html = '<b>' + _T('automation', 'trigger') + ':</b> ' +
            Ext.util.Format.htmlEncode(record.get('trigger_type')) + '<br/>';

        html += '<b>' + _T('automation', 'conditions') + ':</b><ul>';
        for (var i = 0; i < conditions.length; i++) {
            html += '<li>' + Ext.util.Format.htmlEncode(
                (conditions[i].type || '') + ' ' +
                (conditions[i].operator || '=') + ' ' +
                (conditions[i].value || '')
            ) + '</li>';
        }
        if (conditions.length === 0) {
            html += '<li>' + _T('automation', 'no_conditions') + '</li>';
        }
        html += '</ul>';

        html += '<b>' + _T('automation', 'actions_col') + ':</b><ul>';
        for (var j = 0; j < actions.length; j++) {
            var desc = actions[j].type || '?';
            if (actions[j].path) { desc += ' \u2192 ' + Ext.util.Format.htmlEncode(actions[j].path); }
            if (actions[j].labels) { desc += ' [' + Ext.util.Format.htmlEncode(actions[j].labels) + ']'; }
            html += '<li>' + desc + '</li>';
        }
        html += '</ul>';

        this.detailPanel.body.update(html);
    },

    onAddRule: function () {
        var self = this;
        Ext.Msg.prompt(_T('automation', 'add_rule'), _T('automation', 'rule_name'), function (btn, name) {
            if (btn === 'ok' && name) {
                SYNO.API.Request({
                    api: 'SYNO.Transmission.Automation',
                    method: 'add_rule',
                    version: 1,
                    params: {
                        name: name,
                        trigger_type: 'on-complete',
                        conditions: '[]',
                        actions: '[]'
                    },
                    callback: function (success) {
                        if (success) {
                            self.loadRules();
                        } else {
                            SYNO.SDS.TransmissionManager.Util.showError(_T('error', 'add_failed'));
                        }
                    }
                });
            }
        });
    },

    onDeleteRule: function () {
        var record = this.ruleGrid.getSelectionModel().getSelected();
        if (!record) { return; }

        var self = this;
        Ext.Msg.confirm(_T('common', 'confirm'), _T('automation', 'confirm_delete'), function (btn) {
            if (btn === 'yes') {
                SYNO.API.Request({
                    api: 'SYNO.Transmission.Automation',
                    method: 'delete_rule',
                    version: 1,
                    params: { rule_id: record.get('id') },
                    callback: function (success) {
                        if (success) {
                            self.loadRules();
                            self.detailPanel.body.update('');
                        }
                    }
                });
            }
        });
    },

    onTestRule: function () {
        var record = this.ruleGrid.getSelectionModel().getSelected();
        if (!record) { return; }

        SYNO.API.Request({
            api: 'SYNO.Transmission.Automation',
            method: 'test_rule',
            version: 1,
            params: { rule_id: record.get('id') },
            callback: function (success, response) {
                if (success && response && response.matches) {
                    var msg = _T('automation', 'test_matches') + ': ' + response.matches.length;
                    if (response.matches.length > 0) {
                        msg += '\n';
                        for (var i = 0; i < Math.min(response.matches.length, 5); i++) {
                            msg += '\n\u2022 ' + (response.matches[i].name || 'unknown');
                        }
                    }
                    Ext.Msg.alert(_T('automation', 'test_rule'), msg);
                }
            }
        });
    }
});

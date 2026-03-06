/**
 * SettingsPanel.js — Settings window for Transmission configuration.
 *
 * Four tabs:
 *   1. Connection: host, port, username, password, test button
 *   2. Speed: download/upload limits, alt-speed schedule
 *   3. Download: default dir, incomplete dir, ratio/idle limits
 *   4. Peers: encryption, DHT/PEX/LPD/UTP, port, blocklist
 *
 * Loads current settings via SYNO.Transmission.Settings.get on open,
 * saves via SYNO.Transmission.Settings.set on apply.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.SettingsPanel = Ext.extend(SYNO.SDS.ModalWindow, {

    constructor: function (config) {
        // Connection tab
        this.connectionForm = new Ext.form.FormPanel({
            title: _T('settings', 'connection'),
            bodyStyle: 'padding: 15px',
            border: false,
            labelWidth: 150,
            defaults: { anchor: '100%' },
            items: [
                {
                    xtype: 'textfield',
                    fieldLabel: _T('settings', 'host'),
                    name: 'rpc_host',
                    value: 'localhost'
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'port'),
                    name: 'rpc_port',
                    value: 9091,
                    minValue: 1,
                    maxValue: 65535
                },
                {
                    xtype: 'textfield',
                    fieldLabel: _T('settings', 'username'),
                    name: 'rpc_username'
                },
                {
                    xtype: 'textfield',
                    fieldLabel: _T('settings', 'password'),
                    name: 'rpc_password',
                    inputType: 'password'
                },
                {
                    xtype: 'button',
                    text: _T('settings', 'test_connection'),
                    style: 'margin-top: 10px',
                    handler: this.onTestConnection,
                    scope: this
                }
            ]
        });

        // Speed tab
        this.speedForm = new Ext.form.FormPanel({
            title: _T('settings', 'speed'),
            bodyStyle: 'padding: 15px',
            border: false,
            autoScroll: true,
            labelWidth: 180,
            defaults: { anchor: '100%' },
            items: [
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'speed_limit_down_enabled'),
                    name: 'speed-limit-down-enabled'
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'speed_limit_down'),
                    name: 'speed-limit-down',
                    minValue: 0
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'speed_limit_up_enabled'),
                    name: 'speed-limit-up-enabled'
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'speed_limit_up'),
                    name: 'speed-limit-up',
                    minValue: 0
                },
                { xtype: 'spacer', height: 10 },
                {
                    xtype: 'label',
                    text: _T('settings', 'alt_speed_title'),
                    style: 'font-weight: bold; display: block; margin-bottom: 8px'
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'alt_speed_enabled'),
                    name: 'alt-speed-enabled'
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'alt_speed_down'),
                    name: 'alt-speed-down',
                    minValue: 0
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'alt_speed_up'),
                    name: 'alt-speed-up',
                    minValue: 0
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'alt_speed_time_enabled'),
                    name: 'alt-speed-time-enabled'
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'alt_speed_time_begin'),
                    name: 'alt-speed-time-begin',
                    minValue: 0,
                    maxValue: 1440
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'alt_speed_time_end'),
                    name: 'alt-speed-time-end',
                    minValue: 0,
                    maxValue: 1440
                }
            ]
        });

        // Download tab
        this.downloadForm = new Ext.form.FormPanel({
            title: _T('settings', 'download'),
            bodyStyle: 'padding: 15px',
            border: false,
            autoScroll: true,
            labelWidth: 180,
            defaults: { anchor: '100%' },
            items: [
                {
                    xtype: 'textfield',
                    fieldLabel: _T('settings', 'download_dir'),
                    name: 'download-dir'
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'incomplete_dir_enabled'),
                    name: 'incomplete-dir-enabled'
                },
                {
                    xtype: 'textfield',
                    fieldLabel: _T('settings', 'incomplete_dir'),
                    name: 'incomplete-dir'
                },
                { xtype: 'spacer', height: 10 },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'ratio_limit_enabled'),
                    name: 'ratio-limit-enabled'
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'ratio_limit'),
                    name: 'ratio-limit',
                    decimalPrecision: 2,
                    minValue: 0,
                    step: 0.1
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'idle_limit_enabled'),
                    name: 'idle-seeding-limit-enabled'
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'idle_limit'),
                    name: 'idle-seeding-limit',
                    minValue: 1
                }
            ]
        });

        // Peers tab
        this.peersForm = new Ext.form.FormPanel({
            title: _T('settings', 'peers'),
            bodyStyle: 'padding: 15px',
            border: false,
            autoScroll: true,
            labelWidth: 180,
            defaults: { anchor: '100%' },
            items: [
                {
                    xtype: 'combo',
                    fieldLabel: _T('settings', 'encryption'),
                    name: 'encryption',
                    store: new Ext.data.ArrayStore({
                        fields: ['value', 'display'],
                        data: [
                            [0, _T('settings', 'encryption_tolerated')],
                            [1, _T('settings', 'encryption_preferred')],
                            [2, _T('settings', 'encryption_required')]
                        ]
                    }),
                    valueField: 'value',
                    displayField: 'display',
                    mode: 'local',
                    editable: false,
                    triggerAction: 'all',
                    value: 1
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'dht_enabled'),
                    name: 'dht-enabled'
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'pex_enabled'),
                    name: 'pex-enabled'
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'lpd_enabled'),
                    name: 'lpd-enabled'
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'utp_enabled'),
                    name: 'utp-enabled'
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'port_forwarding'),
                    name: 'port-forwarding-enabled'
                },
                {
                    xtype: 'numberfield',
                    fieldLabel: _T('settings', 'peer_port'),
                    name: 'peer-port',
                    minValue: 1024,
                    maxValue: 65535
                },
                {
                    xtype: 'checkbox',
                    fieldLabel: _T('settings', 'blocklist_enabled'),
                    name: 'blocklist-enabled'
                }
            ]
        });

        this.tabPanel = new Ext.TabPanel({
            activeTab: 0,
            deferredRender: false,
            items: [
                this.connectionForm,
                this.speedForm,
                this.downloadForm,
                this.peersForm
            ]
        });

        var self = this;
        var cfg = Ext.apply({
            title: _T('settings', 'title'),
            width: 550,
            height: 420,
            layout: 'fit',
            modal: true,
            items: [this.tabPanel],
            buttons: [
                {
                    text: _T('settings', 'save'),
                    handler: function () {
                        self.onSave();
                    }
                },
                {
                    text: _T('common', 'cancel'),
                    handler: function () {
                        self.close();
                    }
                }
            ]
        }, config);

        SYNO.SDS.TransmissionManager.SettingsPanel.superclass.constructor.call(this, cfg);
        this.loadSettings();
    },

    /**
     * Load current Transmission settings from the API.
     */
    loadSettings: function () {
        var self = this;
        SYNO.API.Request({
            api: 'SYNO.Transmission.Settings',
            method: 'get',
            version: 1,
            callback: function (success, response) {
                if (success && response) {
                    self.populateSettings(response);
                }
            }
        });
    },

    /**
     * Populate form fields from API response.
     *
     * @param {Object} settings Transmission session settings
     */
    populateSettings: function (settings) {
        this.speedForm.getForm().setValues({
            'speed-limit-down-enabled': settings['speed-limit-down-enabled'] || false,
            'speed-limit-down': settings['speed-limit-down'] || 0,
            'speed-limit-up-enabled': settings['speed-limit-up-enabled'] || false,
            'speed-limit-up': settings['speed-limit-up'] || 0,
            'alt-speed-enabled': settings['alt-speed-enabled'] || false,
            'alt-speed-down': settings['alt-speed-down'] || 0,
            'alt-speed-up': settings['alt-speed-up'] || 0,
            'alt-speed-time-enabled': settings['alt-speed-time-enabled'] || false,
            'alt-speed-time-begin': settings['alt-speed-time-begin'] || 0,
            'alt-speed-time-end': settings['alt-speed-time-end'] || 0
        });

        this.downloadForm.getForm().setValues({
            'download-dir': settings['download-dir'] || '',
            'incomplete-dir-enabled': settings['incomplete-dir-enabled'] || false,
            'incomplete-dir': settings['incomplete-dir'] || '',
            'ratio-limit-enabled': settings['ratio-limit-enabled'] || false,
            'ratio-limit': settings['ratio-limit'] || 2,
            'idle-seeding-limit-enabled': settings['idle-seeding-limit-enabled'] || false,
            'idle-seeding-limit': settings['idle-seeding-limit'] || 30
        });

        this.peersForm.getForm().setValues({
            'encryption': settings['encryption'] !== undefined ? settings['encryption'] : 1,
            'dht-enabled': settings['dht-enabled'] !== undefined ? settings['dht-enabled'] : true,
            'pex-enabled': settings['pex-enabled'] !== undefined ? settings['pex-enabled'] : true,
            'lpd-enabled': settings['lpd-enabled'] || false,
            'utp-enabled': settings['utp-enabled'] !== undefined ? settings['utp-enabled'] : true,
            'port-forwarding-enabled': settings['port-forwarding-enabled'] || false,
            'peer-port': settings['peer-port'] || 51413,
            'blocklist-enabled': settings['blocklist-enabled'] || false
        });
    },

    /**
     * Test the connection to the Transmission daemon.
     */
    onTestConnection: function () {
        SYNO.API.Request({
            api: 'SYNO.Transmission.Settings',
            method: 'test_connection',
            version: 1,
            callback: function (success, response) {
                if (success && response && response.connected) {
                    Ext.Msg.alert(
                        _T('settings', 'connection'),
                        _T('settings', 'connection_success')
                    );
                } else {
                    Ext.Msg.alert(
                        _T('settings', 'connection'),
                        _T('settings', 'connection_failed')
                    );
                }
            }
        });
    },

    /**
     * Save settings to the Transmission daemon.
     */
    onSave: function () {
        var speedValues = this.speedForm.getForm().getFieldValues();
        var downloadValues = this.downloadForm.getForm().getFieldValues();
        var peersValues = this.peersForm.getForm().getFieldValues();

        var settings = {};
        Ext.apply(settings, speedValues);
        Ext.apply(settings, downloadValues);
        Ext.apply(settings, peersValues);

        var self = this;

        SYNO.API.Request({
            api: 'SYNO.Transmission.Settings',
            method: 'set',
            version: 1,
            params: { settings: Ext.encode(settings) },
            callback: function (success) {
                if (success) {
                    self.close();
                } else {
                    SYNO.SDS.TransmissionManager.Util.showError(
                        _T('error', 'settings_failed')
                    );
                }
            }
        });
    }
});

/**
 * AddTorrentWindow.js — Modal dialog for adding a new torrent.
 *
 * Supports two modes:
 *   1. From URL / magnet link
 *   2. From .torrent file upload
 *
 * Toggles between modes via a combobox. Calls SYNO.Transmission.Torrent.add
 * on submit.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.AddTorrentWindow = Ext.extend(SYNO.SDS.ModalWindow, {

    constructor: function (config) {
        this.addEvents('torrentadded');

        // Mode selector
        this.modeCombo = new Ext.form.ComboBox({
            fieldLabel: _T('add', 'from_url'),
            store: new Ext.data.ArrayStore({
                fields: ['value', 'display'],
                data: [
                    ['url', _T('add', 'from_url')],
                    ['file', _T('add', 'from_file')]
                ]
            }),
            valueField: 'value',
            displayField: 'display',
            mode: 'local',
            editable: false,
            triggerAction: 'all',
            value: 'url',
            width: 300,
            listeners: {
                select: this.onModeChange,
                scope: this
            }
        });

        // URL input
        this.urlField = new Ext.form.TextField({
            fieldLabel: _T('add', 'url_or_magnet'),
            name: 'url',
            width: 400,
            allowBlank: false
        });

        // File upload
        this.fileField = new Ext.ux.form.FileUploadField || Ext.form.TextField;
        this.fileUpload = new Ext.form.TextField({
            fieldLabel: _T('add', 'torrent_file'),
            name: 'torrent_file',
            inputType: 'file',
            width: 400,
            hidden: true
        });

        // Download location
        this.downloadDirField = new Ext.form.TextField({
            fieldLabel: _T('add', 'download_location'),
            name: 'download_dir',
            width: 400,
            allowBlank: true
        });

        // Start paused checkbox
        this.pausedCheckbox = new Ext.form.Checkbox({
            fieldLabel: _T('add', 'start_paused'),
            name: 'paused'
        });

        // Labels field
        this.labelsField = new Ext.form.TextField({
            fieldLabel: _T('add', 'labels'),
            name: 'labels',
            width: 400,
            emptyText: _T('add', 'labels_hint')
        });

        this.formPanel = new Ext.form.FormPanel({
            border: false,
            bodyStyle: 'padding: 15px',
            labelWidth: 130,
            fileUpload: true,
            items: [
                this.modeCombo,
                this.urlField,
                this.fileUpload,
                this.downloadDirField,
                this.pausedCheckbox,
                this.labelsField
            ]
        });

        var self = this;

        var cfg = Ext.apply({
            title: _T('add', 'add_torrent'),
            width: 550,
            height: 300,
            layout: 'fit',
            modal: true,
            items: [this.formPanel],
            buttons: [
                {
                    text: _T('add', 'add_torrent'),
                    handler: function () {
                        self.onSubmit();
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

        SYNO.SDS.TransmissionManager.AddTorrentWindow.superclass.constructor.call(this, cfg);
    },

    /**
     * Toggle between URL and file upload mode.
     */
    onModeChange: function (combo, record) {
        var mode = record.get('value');
        if (mode === 'url') {
            this.urlField.show();
            this.fileUpload.hide();
        } else {
            this.urlField.hide();
            this.fileUpload.show();
        }
        this.doLayout();
    },

    /**
     * Validate and submit the add torrent request.
     */
    onSubmit: function () {
        var form = this.formPanel.getForm();
        if (!form.isValid()) {
            return;
        }

        var mode = this.modeCombo.getValue();
        var params = {
            download_dir: this.downloadDirField.getValue() || '',
            paused: this.pausedCheckbox.getValue() ? 'true' : 'false',
            labels: this.labelsField.getValue() || ''
        };

        var self = this;

        if (mode === 'url') {
            params.url = this.urlField.getValue();
            SYNO.API.Request({
                api: 'SYNO.Transmission.Torrent',
                method: 'add',
                version: 1,
                params: params,
                callback: function (success, response) {
                    if (success) {
                        self.fireEvent('torrentadded', response);
                        self.close();
                    } else {
                        SYNO.SDS.TransmissionManager.Util.showError(
                            _T('error', 'add_failed'),
                            response
                        );
                    }
                }
            });
        } else {
            // File upload via form submit
            form.submit({
                url: '/webapi/entry.cgi',
                params: Ext.apply(params, {
                    api: 'SYNO.Transmission.Torrent',
                    method: 'add',
                    version: 1
                }),
                success: function (form, action) {
                    self.fireEvent('torrentadded', action.result);
                    self.close();
                },
                failure: function (form, action) {
                    SYNO.SDS.TransmissionManager.Util.showError(
                        _T('error', 'add_failed'),
                        action.result
                    );
                }
            });
        }
    }
});

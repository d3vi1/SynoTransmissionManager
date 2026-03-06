/**
 * Util.js — Shared formatting and helper utilities.
 *
 * Provides formatSize, formatSpeed, formatEta, and showError.
 * Used by TorrentGrid renderers, DetailPanel, and the status bar.
 */
Ext.ns('SYNO.SDS.TransmissionManager');

SYNO.SDS.TransmissionManager.Util = {

    /**
     * Format a byte count as a human-readable size string.
     *
     * @param {number} bytes Byte count
     * @return {string} e.g. "1.23 GiB"
     */
    formatSize: function (bytes) {
        if (bytes === 0 || bytes === null || bytes === undefined) {
            return '0 B';
        }
        var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        var i = 0;
        var val = bytes;
        while (val >= 1024 && i < units.length - 1) {
            val /= 1024;
            i++;
        }
        return val.toFixed(i === 0 ? 0 : 2) + ' ' + units[i];
    },

    /**
     * Format a speed in bytes/s as a human-readable string.
     *
     * @param {number} bytesPerSec Speed in bytes per second
     * @return {string} e.g. "1.23 MiB/s"
     */
    formatSpeed: function (bytesPerSec) {
        if (!bytesPerSec || bytesPerSec <= 0) {
            return '0 B/s';
        }
        return SYNO.SDS.TransmissionManager.Util.formatSize(bytesPerSec) + '/s';
    },

    /**
     * Format an ETA value (seconds) as a human-readable duration.
     *
     * Transmission ETA codes: -1 = not available, -2 = unknown
     *
     * @param {number} seconds ETA in seconds
     * @return {string} e.g. "2h 15m" or "∞"
     */
    formatEta: function (seconds) {
        if (seconds < 0) {
            return '\u221E';
        }
        if (seconds === 0) {
            return '-';
        }

        var days = Math.floor(seconds / 86400);
        var hours = Math.floor((seconds % 86400) / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);

        if (days > 0) {
            return days + 'd ' + hours + 'h';
        }
        if (hours > 0) {
            return hours + 'h ' + minutes + 'm';
        }
        return minutes + 'm';
    },

    /**
     * Show an error message dialog.
     *
     * @param {string} title Error title
     * @param {Object} response API error response (optional)
     */
    showError: function (title, response) {
        var message = title;
        if (response && response.error && response.error.message) {
            message += ': ' + response.error.message;
        }
        Ext.Msg.alert(_T('error', 'add_failed') || 'Error', message);
    }
};

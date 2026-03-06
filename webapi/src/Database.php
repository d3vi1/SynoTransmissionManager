<?php
/**
 * SQLite database wrapper for TransmissionManager.
 *
 * Manages user-torrent associations, RSS feeds, filters, history,
 * and automation rules.
 */

class Database {
    private $db;

    public function __construct($dbPath = null) {
        if ($dbPath === null) {
            $dbPath = '/var/packages/TransmissionManager/var/transmission.db';
        }
        $this->db = new SQLite3($dbPath);
        $this->db->enableExceptions(true);
    }

    // TODO: Implement database methods (placeholder for bootstrap)
}

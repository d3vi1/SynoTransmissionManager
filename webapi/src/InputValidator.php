<?php
declare(strict_types=1);

/**
 * Centralized input validation for all WebAPI endpoints.
 */
class InputValidator
{
    /** @var int Maximum torrent URL length */
    private const MAX_URL_LENGTH = 4096;

    /** @var int Maximum label length */
    private const MAX_LABEL_LENGTH = 128;

    /** @var int Maximum feed name length */
    private const MAX_NAME_LENGTH = 256;

    /** @var int Maximum file upload size (10 MB) */
    private const MAX_UPLOAD_SIZE = 10485760;

    /**
     * Validate and sanitize a torrent URL or magnet link.
     */
    public static function validateTorrentUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new TransmissionException('URL is required');
        }
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new TransmissionException('URL exceeds maximum length');
        }
        // Allow magnet links
        if (strpos($url, 'magnet:') === 0) {
            return $url;
        }
        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TransmissionException('Invalid URL format');
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new TransmissionException('Only HTTP/HTTPS URLs are allowed');
        }
        return $url;
    }

    /**
     * Validate a positive integer ID.
     */
    public static function validateId($id): int
    {
        $id = (int)$id;
        if ($id <= 0) {
            throw new TransmissionException('Invalid ID: must be a positive integer');
        }
        return $id;
    }

    /**
     * Validate an array of positive integer IDs.
     */
    public static function validateIds(string $idsString): array
    {
        if (trim($idsString) === '') {
            throw new TransmissionException('IDs parameter is required');
        }
        $ids = array_map('intval', explode(',', $idsString));
        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new TransmissionException('Invalid ID in list');
            }
        }
        if (count($ids) > 100) {
            throw new TransmissionException('Too many IDs (max 100)');
        }
        return $ids;
    }

    /**
     * Validate labels string.
     */
    public static function validateLabels(string $labelsString): array
    {
        $labels = array_filter(array_map('trim', explode(',', $labelsString)));
        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                throw new TransmissionException('Label too long (max ' . self::MAX_LABEL_LENGTH . ' chars)');
            }
        }
        if (count($labels) > 20) {
            throw new TransmissionException('Too many labels (max 20)');
        }
        return $labels;
    }

    /**
     * Validate a name/title string.
     */
    public static function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new TransmissionException('Name is required');
        }
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new TransmissionException('Name too long (max ' . self::MAX_NAME_LENGTH . ' chars)');
        }
        return $name;
    }

    /**
     * Validate a file upload.
     */
    public static function validateFileUpload(array $file): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new TransmissionException('File upload failed');
        }
        if ($file['size'] > self::MAX_UPLOAD_SIZE) {
            throw new TransmissionException('File too large (max 10 MB)');
        }
        // Check magic bytes for torrent file (starts with 'd' for bencoded dict)
        $handle = fopen($file['tmp_name'], 'rb');
        if ($handle === false) {
            throw new TransmissionException('Cannot read uploaded file');
        }
        $firstByte = fread($handle, 1);
        fclose($handle);
        if ($firstByte !== 'd') {
            throw new TransmissionException('Invalid torrent file format');
        }
    }

    /**
     * Validate JSON string input.
     */
    public static function validateJson(string $json): array
    {
        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new TransmissionException('Invalid JSON: ' . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new TransmissionException('JSON must decode to an array or object');
        }
        return $data;
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the InputValidator class.
 *
 * Validates all input sanitization and validation methods
 * used across WebAPI endpoints.
 */
class InputValidatorTest extends TestCase
{
    // ---------------------------------------------------------------
    // validateTorrentUrl
    // ---------------------------------------------------------------

    public function testValidateUrlAcceptsValidHttp(): void
    {
        $result = InputValidator::validateTorrentUrl('http://example.com/file.torrent');
        $this->assertSame('http://example.com/file.torrent', $result);
    }

    public function testValidateUrlAcceptsValidHttps(): void
    {
        $result = InputValidator::validateTorrentUrl('https://example.com/file.torrent');
        $this->assertSame('https://example.com/file.torrent', $result);
    }

    public function testValidateUrlAcceptsValidMagnetLink(): void
    {
        $magnet = 'magnet:?xt=urn:btih:c12fe1c06bba254a9dc9f519b335aa7c1367a88a&dn=test';
        $result = InputValidator::validateTorrentUrl($magnet);
        $this->assertSame($magnet, $result);
    }

    public function testValidateUrlRejectsInvalidUrl(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Invalid URL format');
        InputValidator::validateTorrentUrl('not-a-url');
    }

    public function testValidateUrlRejectsEmptyString(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('URL is required');
        InputValidator::validateTorrentUrl('');
    }

    public function testValidateUrlRejectsTooLongUrl(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('URL exceeds maximum length');
        $url = 'https://example.com/' . str_repeat('a', 4100);
        InputValidator::validateTorrentUrl($url);
    }

    public function testValidateUrlRejectsFtpScheme(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Only HTTP/HTTPS URLs are allowed');
        InputValidator::validateTorrentUrl('ftp://example.com/file.torrent');
    }

    public function testValidateUrlTrimsWhitespace(): void
    {
        $result = InputValidator::validateTorrentUrl('  https://example.com/file.torrent  ');
        $this->assertSame('https://example.com/file.torrent', $result);
    }

    // ---------------------------------------------------------------
    // validateId
    // ---------------------------------------------------------------

    public function testValidateIdAcceptsPositiveInteger(): void
    {
        $this->assertSame(42, InputValidator::validateId(42));
    }

    public function testValidateIdAcceptsStringNumber(): void
    {
        $this->assertSame(7, InputValidator::validateId('7'));
    }

    public function testValidateIdRejectsZero(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Invalid ID: must be a positive integer');
        InputValidator::validateId(0);
    }

    public function testValidateIdRejectsNegative(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Invalid ID: must be a positive integer');
        InputValidator::validateId(-5);
    }

    // ---------------------------------------------------------------
    // validateIds
    // ---------------------------------------------------------------

    public function testValidateIdsAcceptsValidString(): void
    {
        $result = InputValidator::validateIds('1,2,3');
        $this->assertSame([1, 2, 3], $result);
    }

    public function testValidateIdsSingleId(): void
    {
        $result = InputValidator::validateIds('42');
        $this->assertSame([42], $result);
    }

    public function testValidateIdsRejectsEmptyString(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('IDs parameter is required');
        InputValidator::validateIds('');
    }

    public function testValidateIdsRejectsWhitespaceOnly(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('IDs parameter is required');
        InputValidator::validateIds('   ');
    }

    public function testValidateIdsRejectsTooMany(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Too many IDs (max 100)');
        $ids = implode(',', range(1, 101));
        InputValidator::validateIds($ids);
    }

    public function testValidateIdsRejectsInvalidIdInList(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Invalid ID in list');
        InputValidator::validateIds('1,0,3');
    }

    // ---------------------------------------------------------------
    // validateLabels
    // ---------------------------------------------------------------

    public function testValidateLabelsAcceptsValid(): void
    {
        $result = InputValidator::validateLabels('movies,tv-shows,music');
        $this->assertSame(['movies', 'tv-shows', 'music'], $result);
    }

    public function testValidateLabelsFiltersEmptyLabels(): void
    {
        $result = InputValidator::validateLabels('movies,,music');
        $this->assertCount(2, $result);
    }

    public function testValidateLabelsRejectsTooLongLabel(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Label too long');
        $label = str_repeat('a', 129);
        InputValidator::validateLabels($label);
    }

    public function testValidateLabelsRejectsTooMany(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Too many labels (max 20)');
        $labels = implode(',', array_fill(0, 21, 'label'));
        InputValidator::validateLabels($labels);
    }

    public function testValidateLabelsTrimsWhitespace(): void
    {
        $result = InputValidator::validateLabels(' movies , tv-shows ');
        $this->assertContains('movies', $result);
        $this->assertContains('tv-shows', $result);
    }

    // ---------------------------------------------------------------
    // validateName
    // ---------------------------------------------------------------

    public function testValidateNameAcceptsValid(): void
    {
        $this->assertSame('My Feed', InputValidator::validateName('My Feed'));
    }

    public function testValidateNameRejectsEmpty(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Name is required');
        InputValidator::validateName('');
    }

    public function testValidateNameRejectsWhitespaceOnly(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Name is required');
        InputValidator::validateName('   ');
    }

    public function testValidateNameRejectsTooLong(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Name too long');
        InputValidator::validateName(str_repeat('x', 257));
    }

    public function testValidateNameTrimsWhitespace(): void
    {
        $this->assertSame('My Feed', InputValidator::validateName('  My Feed  '));
    }

    // ---------------------------------------------------------------
    // validateJson
    // ---------------------------------------------------------------

    public function testValidateJsonAcceptsValidObject(): void
    {
        $result = InputValidator::validateJson('{"key": "value"}');
        $this->assertSame(['key' => 'value'], $result);
    }

    public function testValidateJsonAcceptsValidArray(): void
    {
        $result = InputValidator::validateJson('[1, 2, 3]');
        $this->assertSame([1, 2, 3], $result);
    }

    public function testValidateJsonRejectsInvalid(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Invalid JSON');
        InputValidator::validateJson('{not valid json}');
    }

    public function testValidateJsonRejectsScalarString(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('JSON must decode to an array or object');
        InputValidator::validateJson('"just a string"');
    }

    public function testValidateJsonRejectsScalarNumber(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('JSON must decode to an array or object');
        InputValidator::validateJson('42');
    }

    // ---------------------------------------------------------------
    // validateFileUpload
    // ---------------------------------------------------------------

    public function testValidateFileUploadRejectsUploadError(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('File upload failed');
        InputValidator::validateFileUpload([
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
            'tmp_name' => '',
        ]);
    }

    public function testValidateFileUploadRejectsTooLarge(): void
    {
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('File too large');
        InputValidator::validateFileUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => 11000000,
            'tmp_name' => '',
        ]);
    }

    public function testValidateFileUploadAcceptsValidTorrent(): void
    {
        // Create a temp file that starts with 'd' (bencoded dict)
        $tmp = tempnam(sys_get_temp_dir(), 'torrent_test_');
        file_put_contents($tmp, 'd8:announce35:http://tracker.example.com/announcee');

        try {
            InputValidator::validateFileUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
                'tmp_name' => $tmp,
            ]);
            // If no exception, the validation passed
            $this->assertTrue(true);
        } finally {
            unlink($tmp);
        }
    }

    public function testValidateFileUploadRejectsInvalidFormat(): void
    {
        // Create a temp file that does NOT start with 'd'
        $tmp = tempnam(sys_get_temp_dir(), 'torrent_test_');
        file_put_contents($tmp, 'PK not a torrent file');

        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessage('Invalid torrent file format');

        try {
            InputValidator::validateFileUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
                'tmp_name' => $tmp,
            ]);
        } finally {
            unlink($tmp);
        }
    }
}

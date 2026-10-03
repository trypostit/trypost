<?php

declare(strict_types=1);

namespace App\Services\Media\Sources;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use InvalidArgumentException;

/**
 * A file picked in the Google Picker, downloaded with the user's own
 * `drive.file` access token. The URL is built here from the configured
 * Drive API host and a validated id; the token only travels in the header.
 */
class GoogleDriveFiles
{
    public const string FILE_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * @param  array{id: string, name?: ?string}  $file
     */
    public static function toRemoteFile(string $accessToken, array $file): RemoteFile
    {
        $fileId = (string) data_get($file, 'id');

        if (preg_match(self::FILE_ID_PATTERN, $fileId) !== 1) {
            throw new InvalidArgumentException('Invalid Google Drive file id.');
        }

        $api = rtrim((string) config('trypost.media_sources.google_drive.api'), '/');

        return new RemoteFile(
            "{$api}/files/{$fileId}?alt=media&supportsAllDrives=true",
            (string) data_get($file, 'name', ''),
            ['Authorization' => "Bearer {$accessToken}"],
            Source::GoogleDrive->downloadHosts(),
            Source::GoogleDrive,
            ['file_id' => $fileId],
        );
    }
}

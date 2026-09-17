<?php

namespace QUI\REST\Core\Project\Media;

use Psr\Http\Message\StreamInterface;
use QUI;
use QUI\REST\Core\ApiException;
use RuntimeException;
use Throwable;

final class IncomingFile
{
    public const MAX_BYTES = 52428800;

    /**
     * The caller validates the filename before creating a temporary file.
     * @template T
     * @param callable(string): T $consume
     * @return T
     */
    public static function consume(StreamInterface $Stream, string $filename, callable $consume): mixed
    {
        $directory = QUI::getTemp()->createFolder('rest-media-' . bin2hex(random_bytes(16)));
        chmod($directory, 0700);
        $path = $directory . $filename;
        $Handle = fopen($path, 'xb');

        if ($Handle === false) {
            throw new RuntimeException('Could not open temporary media file.');
        }

        try {
            if ($Stream->isSeekable()) {
                $Stream->rewind();
            }

            $size = 0;

            while (true) {
                $chunk = $Stream->read(65536);
                if ($chunk === '') {
                    if (!$Stream->eof()) {
                        throw new ApiException('invalid_input', 'The upload stream could not be read.', 400);
                    }

                    break;
                }

                $size += strlen($chunk);

                if ($size > self::MAX_BYTES) {
                    throw new ApiException('payload_too_large', 'Uploads are limited to 50 MiB.', 413);
                }

                if (fwrite($Handle, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write temporary media file.');
                }
            }

            fclose($Handle);
            $Handle = null;
            return $consume($path);
        } finally {
            if (is_resource($Handle)) {
                fclose($Handle);
            }

            if (is_file($path)) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}

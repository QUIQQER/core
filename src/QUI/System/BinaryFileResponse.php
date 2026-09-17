<?php

declare(strict_types=1);

namespace QUI\System;

use Symfony\Component\HttpFoundation\BinaryFileResponse as SymfonyBinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Delegate file delivery only when the webserver supplies internal CGI parameters.
 */
class BinaryFileResponse extends SymfonyBinaryFileResponse
{
    public function prepare(Request $request): static
    {
        // Never interpret client HTTP headers as proof of webserver support.
        $Request = clone $request;
        $Request->headers->remove('X-Sendfile-Type');
        $Request->headers->remove('X-Accel-Mapping');
        $this->headers->remove('X-Sendfile');
        $this->headers->remove('X-Accel-Redirect');

        $type = $Request->server->get('QUIQQER_SENDFILE_TYPE');

        if (
            !$this->tempFileObject
            && !$this->deleteFileAfterSend
            && ($Request->isMethod('GET') || $Request->isMethod('HEAD'))
        ) {
            if ($type === 'X-Sendfile') {
                $Request->headers->set('X-Sendfile-Type', $type);
            } elseif ($type === 'X-Accel-Redirect') {
                $mapping = $this->getAccelMapping($Request);

                if ($mapping !== null) {
                    $Request->headers->set('X-Sendfile-Type', $type);
                    $Request->headers->set('X-Accel-Mapping', $mapping);
                }
            }
        }

        // Symfony's switch is process-global. Restore it even in persistent workers.
        $previousTrust = self::$trustXSendfileTypeHeader;
        self::$trustXSendfileTypeHeader = $Request->headers->has('X-Sendfile-Type');

        try {
            return parent::prepare($Request);
        } finally {
            self::$trustXSendfileTypeHeader = $previousTrust;
        }
    }

    private function getAccelMapping(Request $Request): ?string
    {
        $mapping = $Request->server->get('QUIQQER_ACCEL_MAPPING');
        $path = $this->file->getRealPath();

        if (!is_string($mapping) || $path === false || preg_match('/[\x00-\x1F\x7F]/', $mapping)) {
            return null;
        }

        foreach (explode(',', $mapping) as $entry) {
            $parts = explode('=', trim($entry));

            if (count($parts) !== 2) {
                continue;
            }

            [$directory, $uri] = array_map('trim', $parts);

            // Trailing slashes prevent a mapping for /media/ from matching /media-private/.
            if (
                !str_starts_with($directory, '/')
                || !str_ends_with($directory, '/')
                || !str_starts_with($uri, '/')
                || str_starts_with($uri, '//')
                || !str_ends_with($uri, '/')
                || preg_match('/[?\x23\\\\]/', $uri)
                || !str_starts_with($path, $directory)
            ) {
                continue;
            }

            return HeaderUtils::quote($directory) . '=' . HeaderUtils::quote($uri);
        }

        // Remove the offload signal for unmatched files so Symfony still handles ranges.
        return null;
    }
}

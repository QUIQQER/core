<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use chillerlan\QRCode\QRCode;
use QUI;

final class Links
{
    /**
     * Build the public link and its QR code for the authenticated backend.
     *
     * @param array{
     *     id: string,
     *     generation: string,
     *     token: string,
     *     expiresAt: int,
     *     label: string
     * } $session
     * @return array<string, mixed>
     */
    public static function describe(array $session): array
    {
        $base = QUI::getRequest()->getSchemeAndHttpHost()
            . URL_OPT_DIR . 'quiqqer/core/src/QUI/Upload/MobileUpload/bin/';

        // A fragment keeps the bearer secret out of access logs, referrers and server-rendered markup.
        $url = $base . 'index.php#' . $session['id'] . '.' . $session['token'];

        unset($session['token']);

        $QrCode = new QRCode();
        $labels = Labels::get(QUI::getLocale()->getCurrent());

        return [
            ...$session,
            'url' => $url,
            'qr' => $QrCode->render($url),
            'api' => 'ajax_upload_mobileUpload_manage',
            'labels' => $labels
        ];
    }
}

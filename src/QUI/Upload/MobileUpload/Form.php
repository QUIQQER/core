<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use QUI;

final class Form
{
    /**
     * Run the regular form output hooks so installed extensions can add request tokens.
     * A fresh form is fetched before each POST because form tokens may be single-use.
     */
    public static function render(): string
    {
        $action = URL_OPT_DIR . 'quiqqer/core/src/QUI/Upload/MobileUpload/bin/api.php';
        $action = htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<form data-name="request-form" method="post" action="' . $action . '"></form>';

        QUI::getEvents()->fireEvent('outputParseEnd', [&$html]);

        return $html;
    }
}

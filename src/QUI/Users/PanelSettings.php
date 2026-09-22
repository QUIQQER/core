<?php

namespace QUI\Users;

use DOMDocument;
use DOMElement;
use DOMXPath;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Utils\DOM;
use QUI\Utils\XML\Settings;

/**
 * Populate the dynamic sections of the declarative user settings form.
 */
class PanelSettings
{
    /**
     * @param list<string> $files
     * @param list<AuthenticatorInterface> $authenticators Permission-filtered authenticators for this user.
     */
    public static function render(array $files, string $category, User $User, array $authenticators): string
    {
        $Settings = new Settings();
        $Settings->setXMLPath('//user/window');
        $html = $Settings->getCategoriesHtml($files, $category);

        if (!str_contains($html, 'data-name="user-language"') && !str_contains($html, 'data-name="authenticators"')) {
            return $html;
        }

        $Document = new DOMDocument('1.0', 'UTF-8');
        $Document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $Path = new DOMXPath($Document);

        foreach ($Path->query('//select[@data-name="user-language"]') ?: [] as $Select) {
            if (!$Select instanceof DOMElement) {
                continue;
            }

            foreach (QUI::availableLanguages() as $language) {
                $Option = $Document->createElement('option');
                $Option->setAttribute('value', $language);
                $Option->appendChild($Document->createTextNode($language));
                $Select->appendChild($Option);
            }
        }

        // The XML parser derives IDs from conf; both expiration radios share the same conf.
        foreach ($Path->query('//input[@name="expire"]') ?: [] as $Radio) {
            if ($Radio instanceof DOMElement) {
                $Radio->setAttribute('id', $Radio->getAttribute('id') . '-' . $Radio->getAttribute('value'));
            }
        }

        foreach ($Path->query('//div[@data-name="authenticators"]') ?: [] as $Container) {
            if (!$Container instanceof DOMElement) {
                continue;
            }

            if (!$authenticators) {
                $Empty = $Document->createElement('p');
                $Empty->appendChild($Document->createTextNode(QUI::getLocale()->get(
                    'quiqqer/core',
                    'user.settings.authenticators.2faList.empty'
                )));
                $Container->appendChild($Empty);
            }

            foreach ($authenticators as $Authenticator) {
                $enabled = $User->hasAuthenticator($Authenticator::class);
                $Table = $Document->createElement('table');
                $Table->setAttribute('class', 'authenticator data-table' . ($enabled ? ' authenticator-enabled' : ''));
                $Table->setAttribute('data-name', 'authenticator');
                $Table->setAttribute('data-authenticator', $Authenticator::class);
                $Table->setAttribute('data-settings', $enabled || $Authenticator->getSettingsControl() ? '1' : '');
                $Head = $Document->createElement('thead');
                $Row = $Document->createElement('tr');
                $Title = $Document->createElement('th');
                $Title->setAttribute('data-name', 'authenticator-title');
                $Title->appendChild($Document->createTextNode($Authenticator->getTitle()));
                $Row->appendChild($Title);
                $Head->appendChild($Row);
                $Table->appendChild($Head);
                $Container->appendChild($Table);
            }
        }

        $Body = $Document->getElementsByTagName('body')->item(0);

        return $Body === null ? '' : DOM::getInnerHTML($Body);
    }
}

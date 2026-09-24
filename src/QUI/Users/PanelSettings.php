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

        if (
            !str_contains($html, 'data-name="user-language"')
            && !str_contains($html, 'data-name="authenticators"')
            && !str_contains($html, 'data-name="address-list"')
        ) {
            return $html;
        }

        $Document = new DOMDocument('1.0', 'UTF-8');
        $Document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $Path = new DOMXPath($Document);

        // The address grid supplies its own toolbar and layout.
        foreach ($Path->query('//div[@data-name="address-list"]') ?: [] as $Container) {
            if (!$Container instanceof DOMElement) {
                continue;
            }

            $Tables = $Path->query('ancestor::table[1]', $Container);
            $Table = $Tables ? $Tables->item(0) : null;

            if ($Table instanceof DOMElement) {
                $Table->parentNode?->replaceChild($Container, $Table);
            }
        }

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

            // This placeholder contains an interactive list, not explanatory text.
            $Wrapper = $Container->parentNode;

            if ($Wrapper instanceof DOMElement && $Wrapper->getAttribute('class') === 'description') {
                $Wrapper->parentNode?->replaceChild($Container, $Wrapper);
            }

            if (!$authenticators) {
                $Empty = $Document->createElement('p');
                $Empty->appendChild($Document->createTextNode(QUI::getLocale()->get(
                    'quiqqer/core',
                    'user.settings.authenticators.2faList.empty'
                )));
                $Container->appendChild($Empty);
            }

            $List = $Document->createElement('ul');
            $List->setAttribute('class', 'quiqqer-user-authenticators');

            foreach ($authenticators as $Authenticator) {
                $enabled = $User->hasAuthenticator($Authenticator::class);
                $Entry = $Document->createElement('li');
                $Entry->setAttribute('class', 'quiqqer-user-authenticator' . ($enabled ? ' authenticator-enabled' : ''));
                $Entry->setAttribute('data-name', 'authenticator');
                $Entry->setAttribute('data-authenticator', $Authenticator::class);
                $Entry->setAttribute('data-settings', $enabled || $Authenticator->getSettingsControl() ? '1' : '');
                $Icon = $Document->createElement('span');
                $Icon->setAttribute(
                    'class',
                    'quiqqer-user-authenticator-icon ' . (trim($Authenticator->getIcon()) ?: 'fa fa-key')
                );
                $Icon->setAttribute('aria-hidden', 'true');
                $Title = $Document->createElement('span');
                $Title->setAttribute('class', 'quiqqer-user-authenticator-title');
                $Title->setAttribute('data-name', 'authenticator-title');
                $Title->appendChild($Document->createTextNode($Authenticator->getTitle()));
                $Actions = $Document->createElement('div');
                $Actions->setAttribute('class', 'quiqqer-user-authenticator-actions');
                $Actions->setAttribute('data-name', 'authenticator-actions');
                $Entry->appendChild($Icon);
                $Entry->appendChild($Title);
                $Entry->appendChild($Actions);
                $List->appendChild($Entry);
            }

            if ($authenticators) {
                $Container->appendChild($List);
            }
        }

        $Body = $Document->getElementsByTagName('body')->item(0);

        return $Body === null ? '' : DOM::getInnerHTML($Body);
    }
}

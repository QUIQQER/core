<?php

/**
 * This file contains \QUI\Projects\Site\Hreflang
 */

namespace QUI\Projects\Site;

use QUI;

use function count;
use function htmlspecialchars;
use function in_array;

use const ENT_QUOTES;

/**
 * Hreflang link helper
 */
class Hreflang
{
    public function __construct(
        protected QUI\Interfaces\Projects\Site $Site
    ) {
    }

    /**
     * Return <link rel="alternate" hreflang="..."> tags
     */
    public function output(): string
    {
        $Project = $this->Site->getProject();
        $languages = $Project->getLanguages();
        $result = [];
        $renderedLanguages = [];

        foreach ($languages as $language) {
            $language = (string)$language;

            if ($language === '' || isset($renderedLanguages[$language])) {
                continue;
            }

            $url = $this->getLanguageUrl($language);

            if ($url === '') {
                continue;
            }

            $renderedLanguages[$language] = true;
            $result[] = $this->getLinkRel($language, $url);
        }

        if (count($languages) > 1) {
            $defaultLanguage = $Project->getDefaultLang();

            if ($defaultLanguage !== '' && in_array($defaultLanguage, $languages, true)) {
                $url = $this->getLanguageUrl($defaultLanguage);

                if ($url !== '') {
                    $result[] = $this->getLinkRel('x-default', $url);
                }
            }
        }

        return implode("\n", $result);
    }

    /**
     * Return the alternate URL for a project language.
     */
    protected function getLanguageUrl(string $language): string
    {
        $languageLinkAttribute = $language . '-link';

        if ($this->Site->existsAttribute($languageLinkAttribute)) {
            return (string)$this->Site->getAttribute($languageLinkAttribute);
        }

        try {
            $Project = $this->Site->getProject();
            $languageSiteId = $language === $Project->getLang()
                ? $this->Site->getId()
                : (int)($this->Site->getLangIds()[$language] ?? 0);

            // getId($language) falls back to the current ID when a translation is missing.
            // Hreflang must only reference explicitly linked translations.
            if ($languageSiteId <= 0) {
                return '';
            }

            $LanguageProject = QUI::getProject($Project->getName(), $language);
            $LanguageSite = $LanguageProject->get($languageSiteId);

            return $LanguageSite->getUrlRewrittenWithHost();
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Return <link rel="alternate"> tag
     */
    protected function getLinkRel(string $hreflang, string $url): string
    {
        return '<link rel="alternate" hreflang="' . htmlspecialchars($hreflang, ENT_QUOTES) .
            '" href="' . htmlspecialchars($url, ENT_QUOTES) . '" />';
    }
}

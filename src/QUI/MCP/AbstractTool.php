<?php

/**
 * This file contains the \QUI\MCP\AbstractTool
 */

namespace QUI\MCP;

use QUI;
use QUI\AI\MCP\Server;
use QUI\Interfaces\Projects\Media\File as MediaFile;
use QUI\Permissions\Permission;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Edit;

abstract class AbstractTool implements ToolInterface
{
    protected const CORE_MCP_PERMISSION = 'quiqqer.core.mcp.canUse';

    protected static function checkCorePermission(): void
    {
        Permission::setUser(Server::getRequestUser());

        Permission::checkPermission(
            self::CORE_MCP_PERMISSION,
            Server::getRequestUser()
        );
    }

    protected static function checkPermission(string $permission): void
    {
        Permission::checkPermission(
            $permission,
            Server::getRequestUser()
        );
    }

    protected static function getProject(string $project, ?string $lang = null): Project
    {
        if (empty($lang)) {
            return QUI::getProject($project);
        }

        return QUI::getProject($project, $lang);
    }

    protected static function getEditSite(string $project, int $siteId, ?string $lang = null): Edit
    {
        return new Edit(self::getProject($project, $lang), $siteId);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function parseProject(Project $Project): array
    {
        return [
            'name' => $Project->getName(),
            'title' => $Project->getTitle(),
            'lang' => $Project->getLang(),
            'defaultLang' => $Project->getDefaultLang(),
            'languages' => $Project->getLanguages(),
            'template' => $Project->getTemplate() ?: null,
            'host' => $Project->getHost()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function parseSite(Site $Site, bool $withAttributes = false): array
    {
        $Project = $Site->getProject();
        $urls = self::parseSiteUrls($Site);
        $result = [
            'id' => $Site->getId(),
            'project' => $Project->getName(),
            'lang' => $Project->getLang(),
            'parentId' => $Site->getParentId(),
            'name' => $Site->getAttribute('name'),
            'title' => $Site->getAttribute('title'),
            'short' => $Site->getAttribute('short'),
            'type' => $Site->getAttribute('type'),
            'active' => (bool)$Site->getAttribute('active'),
            'url' => $urls['url'],
            'urlWithHost' => $urls['urlWithHost'],
            'languageLinks' => self::parseSiteLanguageLinks($Site)
        ];

        if ($withAttributes) {
            $result['attributes'] = $Site->getAttributes();
        }

        return $result;
    }

    /**
     * @return array{url: ?string, urlWithHost: ?string}
     */
    protected static function parseSiteUrls(Site $Site): array
    {
        $unavailable = [
            'url' => null,
            'urlWithHost' => null
        ];

        if (
            (int)$Site->getAttribute('active') !== 1
            || $Site->getAttribute('deleted')
        ) {
            return $unavailable;
        }

        try {
            // MCP also lists children of unavailable parents. Check before Output logs a URL failure.
            $Site->getLocation();
        } catch (QUI\Exception $Exception) {
            if ($Exception->getCode() !== 705) {
                throw $Exception;
            }

            return $unavailable;
        }

        $url = $Site->getUrlRewritten();

        if ($url === '') {
            return $unavailable;
        }

        return [
            'url' => $url,
            'urlWithHost' => $Site->getUrlRewrittenWithHost()
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function parseSiteLanguageLinks(Site $Site): array
    {
        $Project = $Site->getProject();
        $projectName = $Project->getName();
        $currentLang = $Project->getLang();
        $langIds = $Site->getLangIds();
        $result = [];

        foreach ($Project->getLanguages() as $lang) {
            $lang = (string)$lang;

            if ($lang === '') {
                continue;
            }

            $linkedId = $lang === $currentLang ? $Site->getId() : ($langIds[$lang] ?? null);

            if (empty($linkedId)) {
                $result[$lang] = [
                    'id' => null,
                    'project' => $projectName,
                    'lang' => $lang,
                    'exists' => false,
                    'active' => null,
                    'url' => null,
                    'urlWithHost' => null,
                    'source' => 'missing'
                ];

                continue;
            }

            try {
                $LinkedSite = $lang === $currentLang
                    ? $Site
                    : new Edit(self::getProject($projectName, $lang), (int)$linkedId);
                $urls = self::parseSiteUrls($LinkedSite);

                $result[$lang] = [
                    'id' => $LinkedSite->getId(),
                    'project' => $projectName,
                    'lang' => $lang,
                    'exists' => true,
                    'active' => (bool)$LinkedSite->getAttribute('active'),
                    'url' => $urls['url'],
                    'urlWithHost' => $urls['urlWithHost'],
                    'source' => $lang === $currentLang ? 'current' : 'multilingual'
                ];
            } catch (\Throwable) {
                $result[$lang] = [
                    'id' => (int)$linkedId,
                    'project' => $projectName,
                    'lang' => $lang,
                    'exists' => false,
                    'active' => null,
                    'url' => null,
                    'urlWithHost' => null,
                    'source' => $lang === $currentLang ? 'current' : 'multilingual'
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function parseMediaItem(MediaFile $Item, bool $withAttributes = false): array
    {
        $Project = $Item->getProject();
        $result = [
            'id' => $Item->getId(),
            'project' => $Project->getName(),
            'name' => $Item->getAttribute('name'),
            'type' => $Item->getAttribute('type'),
            'mimeType' => $Item->getAttribute('mime_type'),
            'title' => $Item->getAttribute('title'),
            'short' => $Item->getAttribute('short'),
            'url' => $Item->getUrl(true)
        ];

        if ($withAttributes) {
            $result['attributes'] = $Item->getAttributes();
        }

        return $result;
    }

    protected static function parseLimit(?int $limit, ?int $offset = null): string
    {
        return ((int)max(0, $offset ?? 0)) . ',' . self::sanitizeLimit($limit);
    }

    protected static function sanitizeLimit(?int $limit): int
    {
        if (empty($limit)) {
            return 50;
        }

        return (int)min(100, max(1, $limit));
    }
}

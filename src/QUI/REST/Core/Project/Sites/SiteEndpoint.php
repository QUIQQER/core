<?php

namespace QUI\REST\Core\Project\Sites;

use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Projects\Site;
use QUI\Projects\Site\Edit;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\Project\ProjectEndpoint;

abstract class SiteEndpoint extends ProjectEndpoint
{
    protected const FIELDS = [
        'name' => 'string', 'title' => 'string', 'short' => 'string', 'content' => 'string',
        'layout' => 'string', 'nav_hide' => 'boolean', 'hide' => 'boolean',
        'release_from' => 'string', 'release_to' => 'string',
        'meta_description' => 'string', 'meta_keywords' => 'string',
        'quiqqer.site.template' => 'string', 'quiqqer.meta.site.robots' => 'string',
        'quiqqer.meta.site.title' => 'string', 'quiqqer.meta.site.description' => 'string',
        'quiqqer.meta.site.canonical' => 'string', 'image_emotion' => 'string', 'image_site' => 'string'
    ];

    /** @param array<string, string> $arguments */
    protected static function site(array $arguments, string $permission = 'view', ?int $id = null): Edit
    {
        $id ??= self::siteId($arguments['siteId']);
        $Project = self::project($arguments['project'], $arguments['lang']);
        $Site = new Edit($Project, $id);
        $Site->checkPermission('quiqqer.projects.site.' . $permission, QUI::getUserBySession());

        if ($Site->getAttribute('deleted')) {
            throw new ApiException('not_found', 'The requested site does not exist.', 404);
        }

        return $Site;
    }

    protected static function siteId(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new ApiException('invalid_site_id', 'siteId must be a positive integer.');
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new ApiException('invalid_site_id', 'siteId must be a positive integer.');
        }

        return $id;
    }

    /** @return array<string, mixed> */
    protected static function siteData(Site $Site): array
    {
        $data = [
            'id' => $Site->getId(),
            'project' => $Site->getProject()->getName(),
            'lang' => $Site->getProject()->getLang(),
            'parentId' => $Site->getParentId(),
            'active' => (bool)$Site->getAttribute('active'),
            'type' => $Site->getAttribute('type'),
            'url' => $Site->getUrlRewritten(),
            'languageLinks' => (object)$Site->getLangIds()
        ];

        foreach (self::FIELDS as $field => $type) {
            $value = $Site->getAttribute($field);
            $data[$field] = $type === 'boolean' ? (bool)$value : (string)$value;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    protected static function siteInput(ServerRequestInterface $Request, bool $create = false): array
    {
        $fields = self::FIELDS;

        if ($create) {
            $fields['parentId'] = 'integer';
        }

        $body = Input::body($Request, $fields, $create ? ['parentId', 'name'] : []);

        if ($body === [] || (isset($body['name']) && trim($body['name']) === '')) {
            throw new ApiException('invalid_input', 'Provide site fields and a non-empty name when setting it.');
        }

        return $body;
    }
}

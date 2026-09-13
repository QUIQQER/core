<?php

namespace QUI\REST\Core\Project\Trash;

final class ListSiteTrash extends TrashEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/{lang}/sites/trash';
    protected const MEDIA = false;
    protected const ACTION = 'list';
}

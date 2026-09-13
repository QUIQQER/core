<?php

namespace QUI\REST\Core\Project\Trash;

final class ClearSiteTrash extends TrashEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites/trash/clear';
    protected const MEDIA = false;
    protected const ACTION = 'clear';
}

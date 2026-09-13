<?php

namespace QUI\REST\Core\Project\Trash;

final class RestoreSiteTrash extends TrashEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites/trash/restore';
    protected const MEDIA = false;
    protected const ACTION = 'restore';
}

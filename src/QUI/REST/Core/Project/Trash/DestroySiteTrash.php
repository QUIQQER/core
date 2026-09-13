<?php

namespace QUI\REST\Core\Project\Trash;

final class DestroySiteTrash extends TrashEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites/trash/destroy';
    protected const MEDIA = false;
    protected const ACTION = 'destroy';
}

<?php

namespace QUI\REST\Core\Project\Trash;

final class RestoreMediaTrash extends TrashEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/trash/restore';
    protected const MEDIA = true;
    protected const ACTION = 'restore';
}

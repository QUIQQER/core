<?php

namespace QUI\REST\Core\Project\Trash;

final class DestroyMediaTrash extends TrashEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/trash/destroy';
    protected const MEDIA = true;
    protected const ACTION = 'destroy';
}

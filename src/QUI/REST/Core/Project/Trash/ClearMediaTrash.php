<?php

namespace QUI\REST\Core\Project\Trash;

final class ClearMediaTrash extends TrashEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/trash/clear';
    protected const MEDIA = true;
    protected const ACTION = 'clear';
}

<?php

namespace QUI\REST\Core\Project\Trash;

final class ListMediaTrash extends TrashEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/media/trash';
    protected const MEDIA = true;
    protected const ACTION = 'list';
}

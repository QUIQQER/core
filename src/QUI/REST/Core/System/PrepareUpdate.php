<?php

namespace QUI\REST\Core\System;

final class PrepareUpdate extends UpdateEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/system/updates/prepare';
    protected const ACTION = 'prepare';
}

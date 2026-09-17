<?php

namespace QUI\REST\Core\System;

final class ListActiveUpdates extends UpdateEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/system/updates/active';
    protected const ACTION = 'active';
}

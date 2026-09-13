<?php

namespace QUI\REST\Core\System;

final class ListUpdates extends UpdateEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/system/updates';
    protected const ACTION = 'history';
}

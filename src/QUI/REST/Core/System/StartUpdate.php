<?php

namespace QUI\REST\Core\System;

final class StartUpdate extends UpdateEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/system/updates';
    protected const ACTION = 'start';
}

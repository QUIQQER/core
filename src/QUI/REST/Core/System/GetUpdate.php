<?php

namespace QUI\REST\Core\System;

final class GetUpdate extends UpdateEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/system/updates/{updateId}';
    protected const ACTION = 'status';
}

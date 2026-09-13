<?php

namespace QUI\REST\Core\System;

final class CancelUpdate extends UpdateEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/system/updates/{updateId}/cancel';
    protected const ACTION = 'cancel';
}

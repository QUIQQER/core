<?php

namespace QUI\MCP\Forwarding;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\System\Forwarding;
use Throwable;

class ListForwardings extends AbstractForwardingTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (): CallToolResult | array {
                try {
                    self::checkForwardingPermission();
                    $config = Forwarding::getList(true)->toArray();
                    $forwardings = [];
                    ksort($config);

                    foreach ($config as $source => $data) {
                        if (!is_array($data)) {
                            continue;
                        }

                        $forwardings[] = self::parseForwarding((string)$source, $data);
                    }

                    return [
                        'count' => count($forwardings),
                        'forwardings' => $forwardings
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_forwardings_list',
            description: 'Lists global QUIQQER Core forwarding rules.'
        );
    }
}

<?php
// filepath: /opt/lampp/htdocs/autobot/includes/bot/BotHandlerFactory.php

require_once __DIR__ . '/BotHandlerInterface.php';
require_once __DIR__ . '/RouterV1Handler.php';
require_once __DIR__ . '/RouterV2BoxDesignHandler.php';

class BotHandlerFactory
{
    public static function get(string $handlerKey): BotHandlerInterface
    {
        $key = strtolower(trim($handlerKey));

        // ✅ CRITICAL: Log handler selection for production debugging
        Logger::info('[FACTORY] Handler selection', [
            'handler_key_original' => $handlerKey,
            'handler_key_normalized' => $key,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        switch ($key) {
            case 'router_v2_boxdesign':
            case 'boxdesign_v2':
                Logger::info('[FACTORY] ✅ Instantiating RouterV2BoxDesignHandler', [
                    'handler_class' => 'RouterV2BoxDesignHandler',
                ]);
                return new RouterV2BoxDesignHandler();

            case 'router_v1':
            default:
                Logger::info('[FACTORY] ✅ Instantiating RouterV1Handler', [
                    'handler_class' => 'RouterV1Handler',
                    'is_default' => ($key !== 'router_v1'),
                ]);
                return new RouterV1Handler();
        }
    }
}

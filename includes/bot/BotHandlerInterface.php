<?php
// filepath: /opt/lampp/htdocs/autobot/includes/bot/BotHandlerInterface.php

interface BotHandlerInterface
{
    /**
     * Handle incoming message and return structured result
     *
     * @param array $context Full context: customer, channel, bot_profile, integrations, message
     * @return array ['reply_text' => string, 'actions' => array, 'meta' => array]
     */
    public function handleMessage(array $context): array;
}

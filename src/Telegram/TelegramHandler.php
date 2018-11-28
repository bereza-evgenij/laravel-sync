<?php

namespace Bereza\LaravelSync\Telegram;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\MissingExtensionException;
use Monolog\Handler\Curl;
use Monolog\Logger;

/**
 * Class TelegramHandler
 * @package Bereza\LaravelSync\Telegram
 */
class TelegramHandler extends AbstractProcessingHandler
{
    /**
     * @var int|string
     */
    private $token;

    /**
     * @var int|string
     */
    private $chatId;

    /**
     * @param string $token Telegram API token
     * @param $chatId
     * @param int|string $level The minimum logging level at which this handler will be triggered
     * @param bool $bubble Whether the messages that are handled can bubble up the stack or not
     *
     * @throws MissingExtensionException
     */
    public function __construct(
        $token,
        $chatId,
        $level = Logger::ALERT,
        $bubble = true
    ) {
        if (!extension_loaded('curl')) {
            throw new MissingExtensionException('Curl PHP extension is required to use the TelegramHandler');
        }

        $this->token = $token;
        $this->chatId = $chatId;

        parent::__construct($level, $bubble);
    }

    /**
     * Builds the body of API call.
     *
     * @param array $record
     *
     * @return string
     */
    protected function buildContent(array $record)
    {
        $content = [
            'chat_id' => $this->chatId,
            'text' => $record['formatted'],
        ];

        if ($this->formatter instanceof TelegramFormatter) {
            $content['parse_mode'] = 'HTML';
        }

        return json_encode($content);
    }

    /**
     * {@inheritdoc}
     *
     * @param array $record
     */
    protected function write(array $record)
    {
        $content = $this->buildContent($record);

        $ch = curl_init();

        $headers = ['Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);

        Curl\Util::execute($ch);
    }
}

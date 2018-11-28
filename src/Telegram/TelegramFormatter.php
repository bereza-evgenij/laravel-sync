<?php

namespace Bereza\LaravelSync\Telegram;

use Monolog\Formatter\NormalizerFormatter;

/**
 * Class TelegramFormatter
 * @package Bereza\LaravelSync\Telegram
 */
class TelegramFormatter extends NormalizerFormatter
{
    /**
     * @var string
     */
    private $title;
    
    /**
     * @inheritDoc
     */
    public function __construct($title = '', $dateFormat = null)
    {
        $this->title = $title;
        parent::__construct($dateFormat);
    }
    
    /**
     * Formats a log record.
     * @param  array $record A record to format
     * @return mixed The formatted record
     */
    public function format(array $record)
    {
        if ($this->title) {
            $output = "<b>{$this->title}</b>" . PHP_EOL;
        }
        $output .= "<b>Site name:</b> " . config('app.name') . PHP_EOL;
        $output .= "<b>Message:</b> {$record['message']}" . PHP_EOL;
        $output .= "<b>Channel:</b> {$record['channel']}" . PHP_EOL;
        $output .= "<b>Environment:</b> " . config('app.env', 'production') . PHP_EOL;
        
        if (defined('SANDBOX')) {
            $output .= "<b>Sandbox:</b> " . SANDBOX . PHP_EOL;
        }
        
        $hostname = gethostname();
        if ($hostname) {
            $output .= "<b>Server:</b> " . $hostname . PHP_EOL;
        }
        $output .= "<b>Time:</b> {$record['datetime']->format($this->dateFormat)}" . PHP_EOL;
        
        if ($record['context']) {
            $output .= PHP_EOL;
            $output .= "[context]" . PHP_EOL;
            $output .= json_encode($record['context'], JSON_UNESCAPED_UNICODE);
        }
        if ($record['extra']) {
            $output .= PHP_EOL;
            $output .= "[context]" . PHP_EOL;
            $output .= json_encode($record['extra'], JSON_UNESCAPED_UNICODE);
        }
        
        return $output;
    }
    
    /**
     * Formats a set of log records.
     * @param  array $records A set of records to format
     * @return mixed The formatted set of records
     */
    public function formatBatch(array $records)
    {
        $message = '';
        foreach ($records as $record) {
            $message .= $this->format($record);
        }
        
        return $message;
    }
}

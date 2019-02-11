<?php

namespace Bereza\LaravelSync;

use Bereza\LaravelSync\Telegram\TelegramFormatter;
use Bereza\LaravelSync\Telegram\TelegramHandler;
use Exception;
use FilesystemIterator;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Bridge\Monolog\Formatter\ConsoleFormatter;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Capsule;

/**
 * Class Sync
 * @package Bereza\LaravelSync
 */
class Sync
{
    /**
     * @var
     */
    protected $name;
    /**
     * @var array
     */
    protected $steps = [];
    
    /**
     * @var string
     */
    protected $logDir = '';
    /**
     * @var string
     */
    protected $logFile = '';
    /**
     * @var bool
     */
    protected $sqlLogsIlluminate = false;
    /**
     * @var array
     */
    protected $sharedData = [];
    
    /**
     * @var Logger
     */
    protected $logger;
    
    /**
     * @var LineFormatter
     */
    protected $formatterForLogger;
    
    /**
     * @var OutputInterface
     */
    protected $output = null;
    
    /**
     * @var string
     */
    protected $lockFile;
    
    /**
     * @var array
     */
    protected $config = [];
    
    /**
     * Sync constructor.
     * @param $name
     * @throws Exception
     */
    public function __construct($name)
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new LogicException(
                sprintf('Недопустимое имя синхронизации "%s", используйте [a-zA-Z0-9_-]+', $name)
            );
        }
        
        $this->name = $name;
        
        $this->logDir = $this->logDir($name);
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        
        $this->logger = new Logger($this->name);
        $this->formatterForLogger = new LineFormatter("[%datetime%] %level_name%: %message% %context%\n");
        $this->logFile = $this->logDir . '/' . date('Y_m_d_H_i_s') . '.log';
        $handler = (new StreamHandler($this->logFile))->setFormatter($this->formatterForLogger);
        $this->logger->pushHandler($handler);
        
        $this->lockFile = $this->logDir . '/' . $this->name . '_is_in_process.lock';
        $userConfig = new Config();
        $this->config = [
            'allowOverlapping' => $userConfig->get('allowOverlapping', false),
            'profileSql' => $userConfig->get('profileSql', false),
            'emailAlertsTo' => $userConfig->get('emailAlertsTo', []),
            'emailFinalLogTo' => $userConfig->get('emailFinalLogTo', []),
            'sendAlertsToTelegram' => $userConfig->get('sendAlertsToTelegram', []),
            'cleanOldLogs' => $userConfig->get('cleanOldLogs', 30),
            'sendOutputToEcho' => $userConfig->get('sendOutputToEcho', false),
            'env' => config('app.env'),
        ];
    }
    
    /**
     * Запуск синхронизации
     * @throws Exception
     */
    public function perform()
    {
        $this->applyConfiguration();
        $this->logger->info('Синхронизация начата');
        $startTime = microtime(true);
        $this->adjustPhpSettings();
        $this->preventOverlapping();
        $this->normalizeSteps();
        $this->validateSteps();
        
        if ($this->config['profileSql']) {
            $this->doProfileSql();
        }
        
        foreach ($this->steps as $step) {
            $step
                ->setLogger($this->logger)
                ->setSqlLoggingParams($this->config['profileSql'], $this->sqlLogsIlluminate)
                ->setSharedData($this->sharedData);
            
            $step->onBeforeLogStart();
            $step->logStart();
            $step->onBeforeLogStart();
            
            try {
                $step->perform();
            } catch (StopStepException $e) {
                if ($step->getStatus() === 'failed') {
                    $status = 'проваленный';
                } elseif ($step->getStatus() === 'skipped') {
                    $status = 'пропущенный';
                } else {
                    $status = 'успешный';
                }
                $this->logger->info('Шаг завершён как ' . $status . '.', [
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]);
            } catch (StopSyncException $e) {
                $this->logger->info('Получена команда на завершение синхронизации.', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                $step->logFinishWithEvents();
                break;
            } catch (Exception $e) {
                $this->logger->alert(
                    'Было выброшено необработанное исключение наследующее \Exception. Синхронизация остановлена.',
                    [
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
                $step->logFinishWithEvents();
                break;
            } catch (Throwable $e) {
                $this->logger->alert(
                    'Было выброшено необработанное исключение реализующее \Throwable. Синхронизация остановлена.',
                    [
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
                $step->logFinishWithEvents();
                break;
            }
            
            $step->logFinishWithEvents();
            
            unset($step);
        }
        
        $this->logger->info('==============================================');
        $this->logger->info('Синхронизация завершена');
        $time = microtime(true) - $startTime;
        if ($time > 60) {
            $this->logger->info("Затраченное время: " . $time / 60 . " минут");
        } else {
            $this->logger->info("Затраченное время: " . $time . " секунд");
        }
        
        $this->doEmailFinalLog();
    }
    
    /**
     * Apply configuration
     * @return $this
     * @throws Exception
     */
    protected function applyConfiguration()
    {
        if ($this->config['emailAlertsTo']) {
            $handler = (new NativeMailerHandler(
                $this->config['emailAlertsTo'],
                $this->getAlertTitle(),
                $this->emailFrom(),
                Logger::ALERT
            ))->setFormatter($this->formatterForLogger);
            $this->logger->pushHandler($handler);
        }
        
        if ($this->config['cleanOldLogs']) {
            $fileSystemIterator = new FilesystemIterator($this->logDir);
            $now = time();
            foreach ($fileSystemIterator as $file) {
                if ($now - $file->getCTime() >= 60 * 60 * 24 * $this->config['cleanOldLogs']) {
                    unlink($this->logDir . '/' . $file->getFilename());
                }
            }
        }
        
        if ($this->config['sendAlertsToTelegram']) {
            $bot = $this->config['sendAlertsToTelegram'][0];
            $channel = $this->config['sendAlertsToTelegram'][1];
            if ($bot && $channel) {
                $handler = new TelegramHandler($bot, $channel, Logger::ALERT);
                $handler->setFormatter(new TelegramFormatter($this->getAlertTitle()));
                $this->logger->pushHandler($handler);
            }
        }
        
        if ($this->config['sendOutputToEcho']) {
            $handler = (new StreamHandler('php://stdout'))->setFormatter($this->formatterForLogger);
            $this->logger->pushHandler($handler);
        }
        
        return $this;
    }
    
    /**
     * Установка шагов синхронизации.
     * @param array $steps
     * @return $this
     */
    public function setSteps(array $steps)
    {
        $this->steps = $steps;
        
        return $this;
    }
    
    /**
     * Перезапись названия окружения.
     * @param $env
     * @return $this
     */
    public function setEnv($env)
    {
        $this->config['env'] = $env;
        
        return $this;
    }
    
    /**
     * Установка значения по-умолчанию для массива передаваемого из шага в шаг.
     * @param array $data
     * @return $this
     */
    public function setSharedData(array $data)
    {
        $this->sharedData = $data;
        
        return $this;
    }
    
    /**
     * Включает дублирование логов в вывод symfony/console.
     * Требуется дополнительный пакет `symfony/monolog-bridge`
     * @param OutputInterface $output
     * @return $this
     */
    public function sendOutputToSymfonyConsoleOutput(OutputInterface $output)
    {
        if (!class_exists('\Symfony\Bridge\Monolog\Handler\ConsoleHandler')) {
            throw new LogicException(
                'Необходимо выполнить `composer require symfony/monolog-bridge` для использования данного метода'
            );
        }
        
        $verbosityLevelMap = [
            OutputInterface::VERBOSITY_QUIET => Logger::ERROR,
            OutputInterface::VERBOSITY_NORMAL => Logger::INFO,
            OutputInterface::VERBOSITY_VERBOSE => Logger::INFO,
            OutputInterface::VERBOSITY_VERY_VERBOSE => Logger::INFO,
            OutputInterface::VERBOSITY_DEBUG => Logger::DEBUG,
        ];
        
        $formatter = new ConsoleFormatter(
            ['format' => "%datetime% %start_tag%%level_name%%end_tag% %message%%context%\n"]
        );
        $handler = (new ConsoleHandler($output, true, $verbosityLevelMap))->setFormatter($formatter);
        $this->logger->pushHandler($handler);
        
        return $this;
    }
    
    /**
     * Включает дублирование логов в echo
     * @param bool $value
     * @return $this
     */
    public function sendOutputToEcho($value = true)
    {
        $this->config['sendOutputToEcho'] = $value;
        
        return $this;
    }
    
    /**
     * Getter for logger
     * @return Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }
    
    /**
     * @param HandlerInterface $handler
     * @return Sync
     */
    public function pushLogHandler(HandlerInterface $handler)
    {
        $this->logger->pushHandler($handler);
        
        return $this;
    }
    
    /**
     * Выключает защиту от наложения синхронизаций друг на друга.
     * @return $this
     */
    public function allowOverlapping()
    {
        $this->config['allowOverlapping'] = true;
        
        return $this;
    }
    
    /**
     * Setter for overlapping
     * @param boolean $value
     * @return $this
     */
    public function setOverlapping($value)
    {
        $this->config['allowOverlapping'] = $value;
        
        return $this;
    }
    
    /**
     * @param bool $value
     * @return $this
     */
    public function profileSql($value = true)
    {
        $this->config['profileSql'] = $value;
        
        return $this;
    }
    
    /**
     * @deprecated use emailAlertsTo()
     * Посылать ошибки на email. По-умолчанию посылает только критические
     * @param array|string $emails
     * @param int $level
     * @return $this
     */
    public function emailErrorsTo($emails, $level = Logger::ALERT)
    {
        return $this->emailAlertsTo($emails);
    }
    
    /**
     * Посылать ошибки уровня alert на email.
     * @param array|string $emails
     * @return $this
     */
    public function emailAlertsTo($emails)
    {
        $this->config['emailAlertsTo'] = $emails;
        
        return $this;
    }
    
    /**
     * @return string
     */
    protected function getAlertTitle()
    {
        return sprintf('%s, %s: ошибка синхронизации "%s"', $this->siteName(), $this->config['env'], $this->name);
    }
    
    /**
     * Посылать ошибки на email. По-умолчанию посылает только критические
     * @param $bot
     * @param $channel
     * @return $this
     */
    public function sendAlertsToTelegram($bot, $channel)
    {
        $this->config['sendAlertsToTelegram'] = [$bot, $channel];
        
        return $this;
    }
    
    /**
     * Послать окончательный лог синхронизации после её окончания на указанный email/email-ы.
     * @param array|string $emails
     * @return $this
     */
    public function emailFinalLogTo($emails)
    {
        $this->config['emailFinalLogTo'] = is_array($emails) ? $emails : (array)$emails;
        
        return $this;
    }
    
    /**
     * Удаляет все логи старше чем $days дней.
     * @param $days
     * @return $this
     */
    public function cleanOldLogs($days = 30)
    {
        $this->config['cleanOldLogs'] = (int)$days;
        
        return $this;
    }
    
    /**
     * Setter for $logdir.
     * @param $dir
     * @return $this
     */
    public function setLogDir($dir)
    {
        $this->logDir = $dir;
        
        return $this;
    }
    
    /**
     * Директория куда попадают логи.
     * Важно чтобы в неё не попадало ничего другого кроме файлов создаваемых данным классом
     * иначе при очистке логов они могут быть удалены.
     * @param $name
     * @return string
     */
    protected function logDir($name)
    {
        return storage_path('logs/' . $name);
    }
    
    /**
     * Email с которого посылаются письма.
     * @return string
     */
    protected function emailFrom()
    {
        $emailFrom = config('mail.from.address');
        
        return $emailFrom ? $emailFrom : 'mail@greensight.ru';
    }
    
    /**
     * Имя сайта которое будет отображено в письмах
     * @return string
     */
    protected function siteName()
    {
        return config('app.name');
    }
    
    /**
     * Метод для донастройки php/приложения перед началом синхронизации.
     */
    protected function adjustPhpSettings()
    {
        if ($this->isUsedDB()) {
            // выставим максимальный таймаут (8 часов) чтобы postgres не отваливался по нему в случае,
            // если есть какой-то долгий шаг без запросов в базу.
            DB::connection()->statement('SET statement_timeout=28800');
            if ($this->checkIlluminate()) {
                Capsule\Manager::select('SET statement_timeout=28800');
            }
        }
    }
    
    /**
     * Непосредственно включает профилирование SQL-запросов.
     * @return $this
     */
    protected function doProfileSql()
    {
        if ($this->isUsedDB()) {
            DB::connection()->enableQueryLog();
            
            if ($this->checkIlluminate()) {
                Capsule\Manager::enableQueryLog();
                $this->sqlLogsIlluminate = true;
            }
        }
        
        return $this;
    }
    
    /**
     * @return bool
     */
    protected function checkIlluminate()
    {
        return false;
    }
    
    /**
     * Защита от наложения синхронизаций друг на друга.
     */
    protected function preventOverlapping()
    {
        if ($this->config['allowOverlapping']) {
            return;
        }
        
        // проверяем существование lock-файла
        if (file_exists($this->lockFile)) {
            $error = $this->config['env'] . ': файл ' . $this->lockFile . ' уже существует. Импорт остановлен.';
            $this->logger->alert($error);
            throw new RuntimeException($error);
        }
        
        // создаем lock-файл
        $fp = fopen($this->lockFile, "w");
        fclose($fp);
        
        // удаляем lock-файл при завершении скрипта
        register_shutdown_function(function ($lockFile) {
            unlink($lockFile);
        }, $this->lockFile);
        
        // или получении сигналов на завершение скрипта
        // работает в php 7.1+
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            
            pcntl_signal(SIGINT, function ($error) {
                $this->logger->error('Синхронизация была прекращена сигналом SIGINT.');
                unlink($this->lockFile);
                exit;
            });
            pcntl_signal(SIGTERM, function ($error) {
                $this->logger->alert('Синхронизация была прекращена сигналом SIGTERM.');
                unlink($this->lockFile);
                exit;
            });
        }
    }
    
    /**
     * Нормализация формата шагов.
     */
    protected function normalizeSteps()
    {
        foreach ($this->steps as $i => $step) {
            if (is_string($step)) {
                $step = new $step;
                $this->steps[$i] = $step;
            }
        }
    }
    
    /**
     * Валидация шагов синхронизации.
     */
    protected function validateSteps()
    {
        $names = [];
        foreach ($this->steps as $step) {
            if (!$step instanceof Step) {
                throw new LogicException(get_class($step) . ' is not an instance of ' . Step::class);
            }
            $names[] = get_class($step);
        }
        
        foreach ($this->steps as $i => $step) {
            foreach ($step->dependsOn as $depends) {
                if (!in_array($depends, $names)) {
                    throw new LogicException(
                        sprintf('There is no "%s" step in sync but "%s" depends on it', $depends, get_class($step))
                    );
                }
                
                if (array_search($depends, $names) > $i) {
                    throw new LogicException(
                        sprintf('Step "%s" depends on step "%s" and must be run after it', get_class($step), $depends)
                    );
                }
            }
        }
    }
    
    /**
     * Непосредственно отправка лога с результатами синхронизации.
     */
    protected function doEmailFinalLog()
    {
        if (!$this->config['emailFinalLogTo']) {
            return;
        }
        
        $headers =
            "From: " . $this->emailFrom() . PHP_EOL .
            "Content-Type: text/plain; charset=utf-8" . PHP_EOL .
            "Content-Transfer-Encoding: 8bit";
        
        $subject = sprintf(
            '%s, %s: синхронизация "%s" завершёна',
            $this->siteName(),
            $this->config['env'],
            $this->name
        );
        $message = file_get_contents($this->logFile);
        if (!$message) {
            $message = 'Не удалось получить файл-лог ' . $this->logFile;
        }
        
        mail(implode(',', $this->config['emailFinalLogTo']), $subject, $message, $headers);
    }
    
    /**
     * Используется ли БД?
     * @return bool
     */
    protected function isUsedDB()
    {
        try {
            DB::connection()->getPdo();
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

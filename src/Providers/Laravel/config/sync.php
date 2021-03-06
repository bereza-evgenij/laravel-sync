<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Разрешить запустить одну и ту же синхронизацию несколько раз
    |--------------------------------------------------------------------------
    */
    'allowOverlapping' => (bool)env('SYNC_ALLOW_OVERLAPPING', false),
    
    /*
    |--------------------------------------------------------------------------
    | Включить профилирование SQL-запросов
    |--------------------------------------------------------------------------
    */
    'profileSql' => (bool)env('SYNC_PROFILE_SQL', false),
    
    /*
    |--------------------------------------------------------------------------
    | E-mail адреса получателей сообщений о критических ошибках
    |--------------------------------------------------------------------------
    */
    'emailAlertsTo' => array_filter(explode(',', env('SYNC_EMAIL_ALERTS_TO', ''))),
    
    /*
    |--------------------------------------------------------------------------
    | E-mail адреса получателей финального лога
    |--------------------------------------------------------------------------
    */
    'emailFinalLogTo' => array_filter(explode(',', env('SYNC_EMAIL_FINAL_LOG_TO', ''))),

    /*
    |--------------------------------------------------------------------------
    | Настройки отправки сообщений о критических ошибках в Telegram
    |--------------------------------------------------------------------------
    */
    'sendAlertsToTelegram' => [env('TELEGRAM_ALERTS_BOT', ''), env('TELEGRAM_ALERTS_CHANNEL', '')],

    /*
    |--------------------------------------------------------------------------
    | Период чистки логов (дней)
    |--------------------------------------------------------------------------
    */
    'cleanOldLogs' => (int)env('SYNC_CLEAN_OLD_LOGS', 30),
    
    /*
    |--------------------------------------------------------------------------
    | Дублирование логов в echo
    |--------------------------------------------------------------------------
    */
    'sendOutputToEcho' => (bool)env('SYNC_SEND_OUTPUT_TO_ECHO', false),

];

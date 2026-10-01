<?php

if (!function_exists('app_timezone_name')) {
    function app_timezone_name(): string
    {
        $configured = trim((string) (
            getenv('APP_TIMEZONE') ?: ($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? '')
            ?: getenv('TIMEZONE') ?: ($_ENV['TIMEZONE'] ?? $_SERVER['TIMEZONE'] ?? '')
            ?: 'Africa/Accra'
        ));
        try {
            new DateTimeZone($configured);
            return $configured;
        } catch (Throwable $exception) {
            error_log('Invalid APP_TIMEZONE "' . $configured . '"; using Africa/Accra.');
            return 'Africa/Accra';
        }
    }
}

if (!function_exists('initialize_app_timezone')) {
    function initialize_app_timezone(?mysqli $connection = null): string
    {
        $timezone = app_timezone_name();
        date_default_timezone_set($timezone);
        if (!defined('APP_TIMEZONE')) {
            define('APP_TIMEZONE', $timezone);
        }

        if ($connection instanceof mysqli) {
            // A numeric offset works even when MySQL's named timezone tables
            // have not been installed (common on XAMPP/shared hosting).
            $offset = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('P');
            if (!preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $offset)) {
                $offset = '+00:00';
            }
            $connection->query("SET time_zone = '" . $offset . "'");
        }

        return $timezone;
    }
}

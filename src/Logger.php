<?php

namespace BiaMiddleware;

class Logger {
    private static string $logDir = '';

    public static function init(string $dir): void {
        self::$logDir = $dir;
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0777, true);
        }
    }

    public static function log(string $level, string $message, array $context = []): void {
        if (empty(self::$logDir)) {
            self::$logDir = dirname(__DIR__) . '/data/logs';
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0777, true);
            }
        }

        $date = date('Y-m-d H:i:s');
        $fileName = self::$logDir . '/' . date('Y-m-d') . '.log';
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $logLine = "[{$date}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        file_put_contents($fileName, $logLine, FILE_APPEND);
    }

    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }

    public static function getRecentLogs(int $limit = 50): array {
        if (empty(self::$logDir)) {
            self::$logDir = dirname(__DIR__) . '/data/logs';
        }
        $fileName = self::$logDir . '/' . date('Y-m-d') . '.log';
        if (!file_exists($fileName)) {
            return [];
        }

        $lines = file($fileName, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $recent = array_slice($lines, -$limit);
        return array_reverse($recent);
    }
}

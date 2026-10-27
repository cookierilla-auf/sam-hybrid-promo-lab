<?php
declare(strict_types=1);
namespace Lab;

final class ActivityLogger
{
    public function __construct(private string $path = __DIR__ . '/../logs/events.jsonl') {}

    public function record(string $event, array $metadata = []): void
    {
        $allowed = ['requestId','campaignId','customerId','messageId','phase','reason','errorType',
            'errorCode','receiveCount','count'];
        $data = ['timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => $event]
            + array_intersect_key($metadata, array_flip($allowed));
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the local log directory');
        }
        if (file_put_contents($this->path, json_encode($data, JSON_THROW_ON_ERROR) . PHP_EOL,
                FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot append the local operational log');
        }
    }

    public static function ids(array $job): array
    {
        return array_intersect_key($job, array_flip(['requestId','campaignId','customerId']));
    }
}

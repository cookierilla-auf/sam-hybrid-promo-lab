<?php
declare(strict_types=1);
namespace Lab;
use Aws\Sqs\SqsClient;

final class Settings
{
    public static function required(string $key): string
    {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            throw new \RuntimeException("Required setting is missing: $key");
        }
        return $value;
    }

    public static function queue(): SqsClient
    {
        return new SqsClient([
            'version' => '2012-11-05',
            'region' => self::required('AWS_REGION'),
            'http' => ['connect_timeout' => 10, 'timeout' => 40],
        ]);
    }

    public static function smtp(): array
    {
        $host = self::required('MAILTRAP_HOST');
        if ($host !== 'sandbox.smtp.mailtrap.io') {
            throw new \RuntimeException('This laboratory permits the Mailtrap Sandbox endpoint only');
        }
        return [
            'host' => $host,
            'port' => (int) (getenv('MAILTRAP_PORT') ?: 2525),
            'username' => self::required('MAILTRAP_USERNAME'),
            'password' => self::required('MAILTRAP_PASSWORD'),
            'from' => getenv('MAIL_FROM_ADDRESS') ?: 'marketing@example.com',
        ];
    }

    public static function processor(\PDO $db, ActivityLogger $logger): MarketingProcessor
    {
        $sender = getenv('LAB_SMTP_DISABLED')==='1' ? new DisabledEmailSender() : new EmailSender(self::smtp());
        return new MarketingProcessor($db, new EmailHistory($db), $sender,
            $logger, max(11, (int) (getenv('MAILTRAP_DELAY_SECONDS') ?: 11)));
    }
}

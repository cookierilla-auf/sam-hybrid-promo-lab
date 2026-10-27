<?php
declare(strict_types=1);
namespace Lab;

/** Safety mode for diagnostic consent/failure tests when no SMTP allowance is available. */
final class DisabledEmailSender implements MailGateway
{
    public function send(array $customer, array $job): string
    {
        throw new \RuntimeException('SMTP is disabled: an eligible job must remain unacknowledged');
    }
}

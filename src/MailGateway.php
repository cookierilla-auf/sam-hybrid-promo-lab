<?php
declare(strict_types=1);
namespace Lab;

interface MailGateway
{
    public function send(array $customer, array $job): string;
}

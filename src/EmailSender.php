<?php
declare(strict_types=1);
namespace Lab;
use PHPMailer\PHPMailer\PHPMailer;

final class EmailSender implements MailGateway
{
    public function __construct(private array $settings) {}

    private function client(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->settings['host'];
        $mail->Port = $this->settings['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->settings['username'];
        $mail->Password = $this->settings['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPOptions = ['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
        $mail->Timeout = 30;
        $mail->Timelimit = 30;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        return $mail;
    }

    public function testConnection(): void
    {
        $mail = $this->client();
        $mail->smtpConnect();
        $mail->smtpClose();
    }

    public function send(array $customer, array $job): string
    {
        if (!preg_match('/@example\.com$/iD',$customer['email'])) {
            throw new \RuntimeException('Only fictional example.com recipients are allowed');
        }
        $mail = $this->client();
        $mail->setFrom($this->settings['from'], 'Laboratory Marketing');
        $mail->addAddress($customer['email'], $customer['full_name']); // E1
        $mail->Subject = $job['subject'];
        $mail->isHTML(false);
        $mail->Body = 'Hello '.$customer['full_name'].",\n\n".$job['body']
            ."\n\nYour promo code: ".$job['promoCode']."\n\nThis is a fictional laboratory promotion."; // E2
        $mail->send();
        return $mail->getLastMessageID();
    }
}

<?php
require __DIR__.'/config.php';
(new Lab\EmailSender(Lab\Settings::smtp()))->testConnection();
echo "Mailtrap Sandbox SMTP authentication successful. No email submitted.\n";

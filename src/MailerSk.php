<?php
namespace Dazz\Legacy;

require_once __DIR__ . '/Mailer.php';

class MailerSk extends Mailer {
    public function __construct(\mysqli $db) {
        parent::__construct($db);
    }

    public function triggerEmailSending(
        $email,
        $senderId,
        $company = 'Hiring Company',
        $contact = 'Hiring Manager',
        $occupation = 'Skilled Personnel',
        $lang = 'sk'
    ) {
        return parent::triggerEmailSending($email, $senderId, $lang, $company, $contact, $occupation);
    }
}

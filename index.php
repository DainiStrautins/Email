<?php

use Email\Controller\PopClient;

require 'vendor/autoload.php';


function retrieveAndProcessEmails(): mixed
{
    // Load configuration from mail_config.json
    $config = json_decode(file_get_contents(__DIR__ . '/config/mail_config.json'), true);

    echo "<pre>";
    $pop3Client = new PopClient($config);
    $pop3Client->connect(); // Connect in the constructor
    $pop3Client->login(); // Login in the constructor
    $pop3Client->coreEmailFunctionality();

    return [];

}
retrieveAndProcessEmails();
<?php

use Email\Controller\PopClient;

require 'vendor/autoload.php';

function retrieveAndProcessEmails(
    ?array $config,
    ?string $configPath = null,
    ?string $dataPath = null,
    ?string $emlPath = null,
    ?string $excelPath = null,
    ?string $customConfigPath = null
): void {
    echo "<pre>";
    $pop3Client = new PopClient($config, $configPath, $dataPath, $emlPath, $excelPath, $customConfigPath);
    $pop3Client->connect(); // Connect in the constructor
    $pop3Client->login(); // Login in the constructor
    $pop3Client->coreEmailFunctionality();
}

// Example 2: Using a custom configuration file path
retrieveAndProcessEmails([], null, null, null, null, 'custom_config.json');

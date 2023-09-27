<?php

namespace Email\Controller;

/**
 * Class Pop3Client
 *
 * This class represents a POP3 email client with various methods for connecting, retrieving emails,
 * and filtering them based on an allowed list.
 *
 * @package YourNamespace\YourPackage
 */
class PopClient {

    /** @var resource|null The POP3 server socket. */
    private $socket;

    /** @var array Configuration options for the POP3 client. */
    private array $config;

    /** @var array An array to store processed emails. */
    private array $processedEmails = [];

    /** @var array A property to store global email headers. */
    private array $globalEmails = [];

    /** @var array A whitelist of allowed email addresses or domains. */
    private array $whitelist = [];

    /** @var array Configuration options for filtering email headers. */
    private array $emailHeaderFilterConfig = [];

    private array $finalizedJsonStructureArray = [];
    const CRLF = "\r\n";

    private string $processedEmailsJson = __DIR__ . '/../../config/processed_emails.json';

    /**
     * Pop3Client constructor.
     *
     * @param array $config The configuration settings for the POP3 client.
     *
     * Initializes the Pop3Client with configuration.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->loadProcessedEmails(); // Load processed emails from JSON file when the Pop3Client is created
        $this->loadWhitelist(); // Load whitelist from the config
        $this->setEmailHeaderFilterConfig();
    }

    /**
     * Pop3Client destructor.
     *
     * Closes the connection to the POP3 server when the instance is destroyed.
     */
    public function __destruct()
    {
        $this->close(); // Close the connection in the destructor
    }

    /**
     * Establishes a connection to the POP3 server.
     *
     * @return bool True if the connection is successful; otherwise, false.
     */
    public function connect(): bool
    {
        $this->socket = stream_socket_client('tls://' . $this->config['hostname'] . ':' . $this->config['port'], $errno, $errstr, 60);
        if (!$this->socket) {
            die("Unable to connect to the POP3 server. Error: $errno - $errstr");
        }
        return true;
    }

    /**
     * Logs in to the POP3 server with the provided username and password.
     *
     * @return void
     */
    public function login(): void
    {
        fwrite($this->socket, "USER {$this->config['username']}". self::CRLF);
        fgets($this->socket); // Read and discard the server's response
        fwrite($this->socket, "PASS {$this->config['password']}". self::CRLF);
        fgets($this->socket); // Read and discard the server's response
    }

    /**
     * Sends a command to the POP3 server.
     *
     * @param string $command The command to send.
     *
     * @return void
     */
    public function sendCommand(string $command): void
    {
        fwrite($this->socket, $command . self::CRLF);
    }

    /**
     * Reads a line of data from the POP3 server.
     *
     * @return string The line of data read from the server.
     */
    public function readLine(): string
    {
        return fgets($this->socket);
    }

    /**
     * Closes the connection to the POP3 server.
     *
     * @return void
     */
    public function close(): void {
        if (is_resource($this->socket)) {
            fwrite($this->socket, "QUIT" . self::CRLF);
            fclose($this->socket);
        }
    }

    /**
     * Loads processed emails from a JSON file into the client.
     *
     * @return void
     */
    private function loadProcessedEmails(): void {
        $processedEmailsJson = file_get_contents($this->processedEmailsJson);
        $this->processedEmails = json_decode($processedEmailsJson, true) ?: [];
    }

    /**
     * Get the configuration options for filtering email headers.
     *
     * @return array The configuration options for email header filtering.
     */
    public function getEmailHeaderFilterConfig(): array
    {
        return $this->emailHeaderFilterConfig;
    }

    /**
     * Loads in email headers filter config from a JSON file into the client.
     *
     * @return void
     */
    public function setEmailHeaderFilterConfig(): void
    {
        $emailHeaderFilterConfigPath = __DIR__ . '/../../config/email_header_filter_config.json';
        $emailHeaderFilterConfig = file_get_contents($emailHeaderFilterConfigPath);
        $this->emailHeaderFilterConfig = json_decode($emailHeaderFilterConfig, true) ?: [];
    }

    /**
     * Loads the whitelist from the json file
     *
     * @return void
     */
    private function loadWhitelist(): void
    {
        $this->whitelist = json_decode(file_get_contents(__DIR__ . '/../../config/allowed_emails.json'), true);
    }

    /**
     * Gets the whitelist configuration.
     *
     * @return array The whitelist configuration.
     */
    public function getWhitelist(): array
    {
        return $this->whitelist;
    }

    /**
     * Lists all emails on the POP3 server.
     *
     * @return array An array of email information.
     */
    private function listAllEmails(): array
    {
        $emailList = [];

        $this->sendCommand("LIST" . self::CRLF);

        // Discard the response for the LIST command
        while ($line = $this->readLine()) {
            // The response should be in the format: "<email_number> <email_size>"
            if (preg_match('/^(\d+) (\d+)$/', trim($line), $matches)) {
                $emailList[] = [
                    'number' => $matches[1],
                    'size' => $matches[2],
                ];
            }

            if (trim($line) === '.') {
                break;
            }
        }

        return $emailList;
    }

    /**
     * Retrieves email headers for a list of emails.
     *
     * @param array $listOfEmails The list of emails to retrieve headers for.
     *
     * @return array An array of email headers.
     */
    private function getEmailHeaders(array $listOfEmails): array
    {
        $emailList = [];

        if (empty($listOfEmails)) {
            return $emailList; // No emails to process, return an empty array
        }

        // Use the TOP command to retrieve headers for all emails
        $emailCount = count($listOfEmails);
        $emailSizes = array_column($listOfEmails, column_key: 'size');

        for ($i = 1; $i <= $emailCount; $i++) {
            $this->sendCommand("TOP $i 0" . self::CRLF);
            $headers = '';
            while ($line = $this->readLine()) {
                if (trim($line) === '-ERR unimplemented' || trim($line) === '+OK') {
                    // Skip this line
                    continue;
                }
                $headers .= $line;
                if (trim($line) === '.') {
                    break;
                }
            }

            $emailList[] = [
                'number' => $i,
                'read_date' => date("d.m.Y H:i:s"),
                'header' => [
                    'raw' => $headers,
                    'size' => $emailSizes[$i - 1], // Use $i - 1 to match the email size with the correct email number
                ],

            ];
        }

        return $emailList; // Return the email headers without setting them globally
    }

    /**
     * Sets Email Headers so its used widely else where
     *
     * @param array $listOfEmails
     * @return void
     */
    private function setGlobalEmailHeaders(array $listOfEmails): void
    {
        // Call getEmailHeaders to retrieve email headers and set them globally
        $this->globalEmails = $this->getEmailHeaders($listOfEmails);
    }

    /**
     * Checks if a string starts with a given prefix.
     *
     * @param string $haystack The string to check.
     * @param string $needle The prefix to search for.
     *
     * @return bool True if the string starts with the given prefix; otherwise, false.
     */
    public function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    /**
     * Extracts the sender email address from a given email header line.
     *
     * @param string $line The email header line to extract the sender from.
     *
     * @return string The extracted sender email address, or an empty string if not found.
     */
    private function extractSenderFromHeader(string $line): string
    {
        $sender = '';

        // Check for known header types
        if ($this->startsWith($line, 'From:') || $this->startsWith($line, 'Return-Path:')) {
            // Extract sender from the "From:" or "Return-Path:" header
            $potentialSender = trim(substr($line, strpos($line, ':') + 1));

            // Check if potentialSender is a valid email address and not empty
            if (filter_var($potentialSender, FILTER_VALIDATE_EMAIL) && !empty($potentialSender)) {
                $sender = $potentialSender;
            }
        }

        return $sender;
    }

    /**
     * Extracts the receiver email addresses from a given email header line.
     *
     * @param string $line The email header line to extract receivers from.
     *
     * @return array An array of extracted receiver email addresses, or an empty array if none are found.
     */
    private function extractReceiversFromHeader(string $line): array
    {
        $receivers = [];

        // Check for the "To:" header to extract receiver(s)
        if ($this->startsWith($line, 'To:')) {
            // Extract the raw receiver string from the "To:" header
            $receiverString = trim(substr($line, strlen('To:')));

            // Split the receiver string into individual email addresses using commas as the separator
            $receiverArray = explode(',', $receiverString);

            // Trim and validate each email address and add it to the receiver array
            foreach ($receiverArray as $receiver) {
                $receiver = trim($receiver);

                // Check if the receiver is a valid email address and not empty
                if (filter_var($receiver, FILTER_VALIDATE_EMAIL) && !empty($receiver)) {
                    $receivers[] = $receiver;
                }
            }
        }

        return $receivers;
    }

    /**
     * Checks if an email is in the whitelist based on its headers.
     *
     * @param string $headers The email headers to check.
     * @param array $whitelist The whitelist configuration.
     *
     * @return bool True if the email is in the whitelist, otherwise, false.
     */
    private function isEmailInWhitelist(string $headers, array $whitelist): bool
    {
        // Initialize sender and receiver variables
        $sender = '';
        $receivers = [];

        // Split the headers into lines
        $headerLines = explode("\r\n", $headers);

        // Iterate through header lines to find sender and receiver
        foreach ($headerLines as $line) {
            $line = trim($line); // Trim leading/trailing spaces

            // Extract sender from the header
            $potentialSender = $this->extractSenderFromHeader($line);
            if (!empty($potentialSender)) {
                $sender = $potentialSender;
            }

            // Extract receivers from the header
            $receiverArray = $this->extractReceiversFromHeader($line);
            if (!empty($receiverArray)) {
                $receivers = array_merge($receivers, $receiverArray);
            }

            // If both sender and receiver are found, break out of the loop
            if ($sender && !empty($receivers)) {
                break;
            }
        }

        // Convert whitelist values to lowercase for case-insensitive comparison
        $senderInWhitelist = in_array(strtolower($sender), array_map('strtolower', $whitelist['whitelist']['senders']));
        $receiversInWhitelist = array_intersect(array_map('strtolower', $receivers), array_map('strtolower', $whitelist['whitelist']['receivers']));

        // Check if the sender and at least one receiver are both non-empty and in the whitelist
        if ($sender && !empty($receivers) && $senderInWhitelist && !empty($receiversInWhitelist)) {
            return true; // Both sender and at least one receiver are in the whitelist
        }

        return false; // Email does not match whitelist criteria
    }

    /**
     * Filters emails down to those in the whitelist.
     *
     * @param array $listOfEmails The list of emails to filter.
     *
     * @return array An array of filtered emails.
     */
    public function filterDownToWhiteListEmails(array $listOfEmails): array
    {
        // Ensure that globalEmails is populated with email headers
        if (empty($this->globalEmails)) {
            $this->setGlobalEmailHeaders($listOfEmails);
        }

        $filteredEmails = [];

        // Access the whitelist using the getWhitelist() method
        $whitelist = $this->getWhitelist();

        // Iterate through globalEmails and filter emails based on whitelist criteria
        foreach ($this->globalEmails as $email) {
            $headers = $email['header']['raw'];
            // Check if the email matches the whitelist criteria (from and to addresses)
            if ($this->isEmailInWhitelist($headers, $whitelist)) {
                $filteredEmails[] = $email;
            }
        }
        return $filteredEmails;
    }

    /**
     * Checks if an email hash exists in the loaded processed emails.
     *
     * @param string $hash The email hash to check.
     *
     * @return bool True if the hash exists, false otherwise.
     */
    private function emailHashExists(string $hash): bool
    {
        // Check if the hash exists in the loaded processed emails
        return isset($this->processedEmails[$hash]);
    }

    /**
     * Processes an email and generates a unique hash for it based on its content or headers.
     *
     * @param array $email An array representing the email.
     *
     * @return array An array containing the email hash and is_duplicate_header status.
     */
    private function processEmail(array $email): array
    {
        // Generate a unique hash for the email based on its content or headers
        // Replace this with your logic to generate the hash
        $hash = md5(json_encode($email['header']));

        // Check if the email hash already exists in processed_emails.json
        $isDuplicate = $this->emailHashExists($hash);

        return [
            'header' => [
                'hash' => $hash,
                'is_duplicate_header' => $isDuplicate ? 'true' : 'false',
            ]
        ];
    }

    /**
     * Generates an array of unique hashes and their is_duplicate_header status for a list of filtered emails.
     *
     * This function processes each filtered email and generates a unique hash for it.
     *
     * @param array $filteredEmails An array of filtered emails to generate hashes for.
     *
     * @return void
     */
    public function generateFilteredEmailHash(array $filteredEmails): void
    {
        foreach ($filteredEmails as &$filteredEmail) {
            $processedEmail = $this->processEmail($filteredEmail);
            $filteredEmail['header']['hash'] = $processedEmail['header']['hash'];
            $filteredEmail['header']['is_duplicate_header'] = $processedEmail['header']['is_duplicate_header'];
        }

        // Update the globalEmails array with the processed emails
        $this->globalEmails = $filteredEmails;
    }

    /**
     * Deletes emails marked as duplicates from the global emails array.
     *
     * @return void
     */
    private function deleteDuplicateEmailsFromGlobalVariable(): void
    {
        $filteredEmails = array_filter($this->globalEmails, function ($email) {
            return $email['header']['is_duplicate_header'] === 'false';
        });

        $this->globalEmails = array_values($filteredEmails);
    }

    /**
     * Filter the raw headers of emails in globalEmails based on a configuration
     *
     * This function iterates through globalEmails and filters the raw headers
     * based on the provided configuration. It checks if the headers match the
     * specified headers to filter and trims header values. Additionally, it checks
     * the "Return-Path" header to ensure it contains a valid email address before
     * including it.
     *
     * @param array $emailHeaderFilterConfig An array containing configuration for
     *                                       filtering email headers.
     *
     * @return void
     */
    private function filterEmailHeaders(array $emailHeaderFilterConfig): void
    {
        // Ensure that globalEmails is not empty
        if (empty($this->globalEmails)) {
            return;
        }

        // Extract headers to filter from the configuration
        $headersToFilter = $emailHeaderFilterConfig['headers_to_filter'];

        // Iterate through globalEmails and filter the raw headers
        foreach ($this->globalEmails as &$email) {
            if (isset($email['header']['raw'])) {
                $filteredHeaders = [];
                $rawHeaders = explode(PHP_EOL, $email['header']['raw']);

                foreach ($rawHeaders as $header) {
                    $headerParts = explode(':', $header, 2);
                    if (count($headerParts) === 2) {
                        $headerName = trim($headerParts[0]);
                        $headerValue = trim($headerParts[1]); // Trim the header value

                        if ($headerName === 'Return-Path') {
                            // Check if the header value is a valid email address
                            if (filter_var($headerValue, FILTER_VALIDATE_EMAIL)) {
                                $filteredHeaders[] = $header;
                            }
                        } elseif (in_array($headerName, $headersToFilter)) {
                            $filteredHeaders[] = $header;
                        }
                    }
                }

                $email['header']['raw'] = implode(PHP_EOL, $filteredHeaders);
            }
        }
    }

    /**
     * Retrieve the content of non-duplicate emails from the global emails.
     *
     * This function retrieves the content of emails marked as non-duplicate from the global emails array.
     *
     * @param array $globalEmails An array of email information, including the "is_duplicate_header" flag.
     *
     * @return array An array of non-duplicate email content, each element containing the email number and content.
     */
    private function retrieveEachEmailContent(array $globalEmails): array
    {
        // Retrieve email content here
        $nonDuplicateEmailContent = [];

        // Loop through globalEmails and find non-duplicate emails
        foreach ($globalEmails as $emailIndex => $email) {
            $emailNumber = $email['number'];
            $this->sendCommand("RETR $emailNumber" . self::CRLF);

            $emailContent = '';
            while ($line = $this->readLine()) {
                if (trim($line) === '-ERR unimplemented' || trim($line) === '+OK') {
                    // Skip this line
                    continue;
                }
                $emailContent .= $line;
                if (trim($line) === '.') {
                    break; // End of email
                }
            }

            // Check if the email content has .xls or .xlsx attachments
            $hasXlsAttachment = preg_match('/Content-Disposition:\s*attachment;\s*filename="[^"]+\.(xlsx|xls)"/i', $emailContent);

            if ($hasXlsAttachment) {
                $nonDuplicateEmailContent[$emailNumber] = [
                    'number' => $emailNumber,
                    'content' => $emailContent,
                ];
            } else {
                // Unset the email if it doesn't have .xls or .xlsx attachment
                unset($globalEmails[$emailIndex]);
            }
        }

        // Re-index the array keys to remove gaps
        $this->globalEmails = array_values($globalEmails);

        return $nonDuplicateEmailContent;
    }

    /**
     * Adds to the global emails array email body content and hashes based on provided data.
     *
     * @param array $emailWithBody An array containing email body content and associated data.
     *
     * @return void
     */
    private function addBodyToGlobalEmailsStructure(array $emailWithBody): void
    {
        foreach ($this->globalEmails as &$email) {
            $numberFromGlobalEmails = $email['number'];
            $email['body']['hash'] = md5($emailWithBody[$numberFromGlobalEmails]['content']);

            // Update the raw field with the email content from $emailWithBody
            $email['body']['raw'] = $emailWithBody[$numberFromGlobalEmails]['content'];
        }
    }


    /**
     * Reindex the globalEmails array by email hash and update the global variable.
     *
     * This function reindex-es the globalEmails array by the email hash and updates
     * the globalEmails class variable with the reindex-ed array.
     *
     * @return void
     */
    private function reindexGlobalEmailsByHash(): void
    {
        $newGlobalEmails = [];

        foreach ($this->globalEmails as $email) {
            $hash = $email['header']['hash'];
            $newGlobalEmails[$hash] = $email;
        }

        $this->globalEmails = $newGlobalEmails;
    }

    /**
     * Get the MIME type based on a file extension.
     *
     * This function maps commonly used file extensions to their corresponding MIME types.
     *
     * @param string $extension The file extension (e.g., 'xlsx', 'xls').
     *
     * @return string The MIME type associated with the given extension.
     *
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types
     */
    private function getMimeTypeFromExtension(string $extension): string
    {
        return match ($extension) {
            'xlsx', 'xls' => 'application/vnd.ms-excel',
            default => 'application/octet-stream',
        };
    }

    /**
     * Process email contents and associate attachments with each email based on its hash.
     *
     * This function also unsets all of those emails who don't have xlsx or xls attachment.
     *
     * @param array $emailContents An array of email contents.
     *
     * @return void
     */
    public function processEmailsWithAttachments(array $emailContents): void
    {
        // Track existing attachment hashes
        $existingAttachmentHashes = [];

        // Iterate through processed emails and extract existing attachment hashes
        foreach ($this->processedEmails as $emailData) {
            if (isset($emailData['attachments'])) {
                $existingAttachmentHashes = array_merge($existingAttachmentHashes, array_keys($emailData['attachments']));
            }
        }
        foreach ($emailContents as $emailHash => $email) {
            $emailContent = $email['body']['raw'];
            $hasValidAttachment = false;

            // Extract attachments
            if (preg_match_all('/Content-Disposition:\s*attachment;\s*filename="([^"]+)"/i', $emailContent, $matches)) {
                foreach ($matches[1] as $attachmentFilename) {
                    $startMarker = "Content-Disposition: attachment; filename=\"$attachmentFilename\"";
                    $startPos = strpos($emailContent, $startMarker);
                    $endPos = strpos($emailContent, "--", $startPos);

                    if ($startPos !== false && $endPos !== false) {
                        $attachmentContent = substr($emailContent, $startPos + strlen($startMarker), $endPos - $startPos - strlen($startMarker));
                        $attachmentContent = trim($attachmentContent);
                        $attachmentContent = preg_replace('/Content-Transfer-Encoding:\s*base64/i', '', $attachmentContent);
                        $decodedContent = base64_decode($attachmentContent);
                        $allowedExtensions = ['xlsx', 'xls'];
                        $extension = strtolower(pathinfo($attachmentFilename, PATHINFO_EXTENSION));

                        if (in_array($extension, $allowedExtensions) && $decodedContent !== false) {
                            $attachmentHash = md5($attachmentContent);

                            // Determine the MIME type based on the file extension
                            $mime = $this->getMimeTypeFromExtension($extension);

                            // Check if this attachment hash already exists
                            if (in_array($attachmentHash, $existingAttachmentHashes)) {
                                // Set is_duplicate to the hash
                                $isDuplicateHash = $attachmentHash;
                            } else {
                                // Add the attachment hash to the list of existing hashes
                                $existingAttachmentHashes[] = $attachmentHash;
                                $isDuplicateHash = 'null';
                            }

                            if (!isset($this->globalEmails[$emailHash]['attachments'])) {
                                $this->globalEmails[$emailHash]['attachments'] = [];
                            }

                            // Create a 'filename' based on the full attachment hash and the original extension
                            $filename = $attachmentHash . '.' . $extension;

                            // Store the filename, extension, and hash
                            $this->globalEmails[$emailHash]['attachments'][$attachmentHash] = [
                                'filename' => $filename,
                                "original_file_name" => $attachmentFilename,
                                'extension' => $extension,
                                'status' => "Pending Approval",
                                'size' => strlen($decodedContent),
                                'raw_base64' => $attachmentContent,
                                'raw' => $decodedContent,
                                'is_duplicate' => $isDuplicateHash,
                                'mime' => $mime,
                            ];

                            $hasValidAttachment = true;
                        }
                    }
                }
            }

            if (!$hasValidAttachment) {
                unset($this->globalEmails[$emailHash]);
            }
        }
    }

    /**
     * Download attachments from email data.
     *
     * This method iterates through the global emails and, if attachments are present,
     * initiates the download process using the EmailAttachmentDownloader class.
     *
     *
     * @return void
     */
    public function downloadEmailAttachments(): void
    {
        $attachmentDownloader = new EmailAttachmentDownloader;
        foreach ($this->globalEmails as $emailData) {
            if (!empty($emailData['attachments'])) {
                $attachmentDownloader->downloadAttachments($emailData['attachments']);
            }
        }
    }

    /**
     * Save filtered emails to individual EML files.
     *
     * This method saves the filtered emails to individual EML files in the 'emails' folder. If an EML file with the same
     * hash already exists, it will not be overwritten.
     *
     * @param array $emails An array of filtered email contents.
     *
     * @return void
     */
    private function saveEMLFiles(array $emails): void
    {
        // Ensure the folder exists, or create it if necessary
        $emlFolderPath = __DIR__ . '/../../emails/';
        if (!is_dir($emlFolderPath)) {
            mkdir($emlFolderPath, 0755, true);
        }

        // Loop through the filtered emails and save each one to its own EML file
        foreach ($emails as $id => $emailContent) {

            // Create the EML file name
            $emlFileName = $emlFolderPath . $id . '.eml';

            // Check if the EML file already exists
            if (!file_exists($emlFileName)) {
                // Save the email content to the EML file
                file_put_contents($emlFileName, $emailContent['body']['raw']);
            }
        }
    }

    /**
     * Extracts Return-Path(s) from email content.
     *
     * @param string $emailContent The email content.
     *
     * @return array An array of Return-Path(s).
     */
    private function extractReturnPath(string $emailContent): array
    {
        $returnPaths = [];

        // Handle Return-Path without angle brackets
        if (preg_match_all('/Return-Path:\s*([^<\r\n]+)/m', $emailContent, $matches)) {
            foreach ($matches[1] as $returnPath) {
                $returnPaths[] = trim($returnPath);
            }
        }

        // Remove duplicate Return-Paths
        return array_unique($returnPaths);
    }

    /**
     * Extracts Delivered-To and To email addresses from email content.
     *
     * @param string $emailContent The email content.
     *
     * @return array An array of email addresses.
     */
    private function extractDeliveredToAndTo(string $emailContent): array
    {
        $emails = [];

        // Extract Delivered-To
        $deliveredToMatches = [];
        if (preg_match_all('/^Delivered-To:\s*([^<\r\n]+)/m', $emailContent, $deliveredToMatches)) {
            $deliveredToEmails = $deliveredToMatches[1];
            foreach ($deliveredToEmails as $email) {
                $emails[] = trim($email);
            }
        }

        // Extract To
        $toMatches = [];
        if (preg_match_all('/^To:\s*([^<\r\n]+)/m', $emailContent, $toMatches)) {
            $toEmails = $toMatches[1];
            foreach ($toEmails as $email) {
                // Split multiple addresses separated by commas
                $emailAddresses = explode(',', $email);
                foreach ($emailAddresses as $address) {
                    $emails[] = trim($address);
                }
            }
        }

        // Remove duplicate email addresses
        return array_unique($emails);
    }

    /**
     * Extracts the email subject from email content.
     *
     * @param string $emailContent The email content.
     *
     * @return string|null The email subject or null if not found.
     */
    function extractSubject(string $emailContent): ?string
    {
        if (preg_match('/^Subject:\s*(.*?)\s*$/m', $emailContent, $matches)) {
            return trim($matches[1]);
        }
        return null; // Return null if not found
    }

    /**
     * Extracts the formatted date from email content.
     *
     * @param string $emailContent The email content.
     *
     * @return string|null The formatted date (d.m.Y H:i:s) or null if not found or unable to parse.
     */
    private function extractDate(string $emailContent): ?string
    {
        if (preg_match('/Date:\s*(.*)\r\n/', $emailContent, $matches)) {
            $rawDate = $matches[1];
            $timestamp = strtotime($rawDate);
            if ($timestamp !== false) {
                return date('d.m.Y H:i:s', $timestamp);
            }
        }
        return null; // Return null if not found or unable to parse
    }

    /**
     * Extracts email headers and appends them to the global emails array.
     *
     * @param array $rawHeaders An array of raw email headers.
     *
     * @return void
     */
    private function extractEmailHeaders(array $rawHeaders): void
    {
        foreach ($rawHeaders as $header) {
            $emailHash = $header['header']['hash']; // Assuming 'hash' is the unique identifier

            $headerInfo = [
                'from' => $this->extractReturnPath($header['header']['raw']),
                'to' => $this->extractDeliveredToAndTo($header['header']['raw']),
                'subject' => $this->extractSubject($header['header']['raw']),
                'date' => $this->extractDate($header['header']['raw']),
            ];

            // Append the header information to the specific email
            $this->globalEmails[$emailHash]['emailInfo'] = $headerInfo;
        }
    }

    /**
     * Create a JSON Structure from Global Email Data.
     *
     * This method iterates through global email data and creates a structured array
     * suitable for JSON serialization, including email details and attachments information.
     *
     * @return void
     */
    private function createJsonStructure(): void
    {
        foreach ($this->globalEmails as $email) {
            $emailInfo = [
                "from" => $email['emailInfo']['from'],
                "to" => $email['emailInfo']['to'],
                "subject" => $email['emailInfo']['subject'],
                "date" => $email['emailInfo']['date'],
                "read_date" => $email['read_date'],
            ];

            // Check if there are attachments
            if (!empty($email['attachments'])) {
                $emailInfo['attachments'] = [];

                // Iterate through the "hash" keys to access attachment information
                foreach ($email['attachments'] as $hash => $attachmentInfo) {
                    $attachmentData = [
                        "filename" => $attachmentInfo['filename'],
                        "original_file_name" => $attachmentInfo['original_file_name'],
                        "extension" => $attachmentInfo['extension'],
                        "status" => $attachmentInfo['status'],
                        "size" => $attachmentInfo['size'],
                        'mime' => $attachmentInfo['mime'],
                        "is_duplicate" => $attachmentInfo['is_duplicate'],
                    ];

                    // Add the attachment data to the attachments' array
                    $emailInfo['attachments'][$hash] = $attachmentData;
                }
            }

            // Add the email info to the finalized JSON structure array
            $this->finalizedJsonStructureArray[$email['header']['hash']] = $emailInfo;
        }
    }

    /**
     * Save Processed Emails into a JSON File.
     *
     * This method merges the new processed emails with the existing processed emails
     * and saves the updated data into a JSON file for future reference.
     *
     * @param array $newEmailData An array containing the new processed email data to be added.
     *
     * @return void
     *
     */
    private function saveIntoJson(array $newEmailData): void
    {
        // Merge the new processed emails with the existing processed emails
        $this->processedEmails = array_merge($this->processedEmails, $newEmailData);

        $jsonEmailData = json_encode($this->processedEmails, JSON_PRETTY_PRINT);
        file_put_contents($this->processedEmailsJson, $jsonEmailData);
    }


    /**
     * Executes the core functionality of the POP3 client:
     *
     * 1. Retrieve email headers for all emails.
     * 2. Filter emails based on a whitelist.
     * 3. Remove duplicate emails.
     * 4. Filter email headers to minimize header content.
     * 5. Retrieve email bodies (including attachments).
     * 6. Close the connection.
     * 7. Add email bodies to the global email structure.
     * 8. Reindex global emails by hash.
     * 9. Process emails and prepare the structure to download and save into Json.
     * 10. Download all email attachments.
     * 11. Save .eml type file of each email.
     * 12. Extract email headers from global emails and make emailInfo array that stores it.
     * 13. Merge the new processed emails with the existing processed emails and save it into Json file.
     * @return void
     */
    public function coreEmailFunctionality(): void
    {
        // 1. Retrieve email headers for all emails.
        $filteredEmails = $this->filterDownToWhiteListEmails($this->listAllEmails());

        // 2. Generate filtered email hashes.
        $this->generateFilteredEmailHash($filteredEmails);

        // 3. Remove duplicate emails.
        // This function also updates the globalEmails variable, making it later.
        $this->deleteDuplicateEmailsFromGlobalVariable();

        // 4. Filter email headers to minimize header content.
        $this->filterEmailHeaders($this->getEmailHeaderFilterConfig());

        // 5. Retrieve email bodies (including attachments).
        $emailWithBody = $this->retrieveEachEmailContent($this->globalEmails);

        // 6. Close the connection since we do not need it anymore in this transaction.
        $this->close();

        if(empty($this->globalEmails)) {
            return;
        }

        // 7. Add email bodies to the global email structure.
        $this->addBodyToGlobalEmailsStructure($emailWithBody);

        // 8. Reindex global emails by hash.
        $this->reindexGlobalEmailsByHash();

        // 9. Process emails and prepare the structure to download and save into Json.
        $this->processEmailsWithAttachments($this->globalEmails);

        // 10. Download email attachments.
        $this->downloadEmailAttachments();

        // 11. Save .eml file for each email full response.
        $this->saveEMLFiles($this->globalEmails);

        // 12. Extract email headers from global emails.
        $this->extractEmailHeaders($this->globalEmails);

        // 13. Creates Json file structure that needs to be saved.
        $this->createJsonStructure();

        // 14. Saves the file into processed_emails.json the created structure variable
        $this->saveIntoJson($this->finalizedJsonStructureArray);
    }

    /**
     * Retrieves a list of Excel files from processed emails.
     *
     * This function searches through processed emails and collects the list of Excel files
     * found in email attachments.
     *
     * @return array An array of Excel files with their attachment data.
     */
    function getListOfExcelFiles(): array
    {
        $attachments = [];
        foreach ($this->processedEmails as $emailData) {
            // Check if there are attachments in this email
            if (isset($emailData['attachments'])) {
                // Add the entire 'attachments' section to the attachments' array
                $attachments[] = [
                    'subject' => $emailData['subject'],
                    'date' => $emailData['date'],
                    'read_date' => $emailData['read_date'],
                    $emailData['attachments'],
                ];
            }
        }

        return $attachments;
    }

    /**
     * Updates attachment status by filenames.
     *
     * This function updates the status of attachments in processed emails based on
     * specified filenames and new status.
     *
     * @param array  $fullFilenames An array of full filenames (with an extension and path).
     * @param string $newStatus     The new status to set for the attachments.
     *
     * @return void
     */
    public function updateAttachmentStatusByFilenames(array $fullFilenames, string $newStatus): void
    {
        // Load the JSON file that contains attachment information
        $attachments = json_decode(file_get_contents($this->processedEmailsJson), true);

        // Iterate through each full filename in the array
        foreach ($fullFilenames as $fullFilename) {
            // Extract the partial filename (remove the extension and any path)
            $partialFilename = pathinfo($fullFilename, PATHINFO_FILENAME);
            // Extract the extension from the full filename
            $extension = pathinfo($fullFilename, PATHINFO_EXTENSION);

            // Iterate through each email in the JSON
            foreach ($this->processedEmails as $emailId => $emailData) {
                // Check if the email has attachments
                if (isset($emailData['attachments'])) {
                    // Check if the partial filename exists in the attachments
                    if (isset($emailData['attachments'][$partialFilename])) {
                        // Check if the extension matches the JSON record
                        if ($emailData['attachments'][$partialFilename]['extension'] === $extension) {
                            // Check if the current status is different from the new status
                            if ($emailData['attachments'][$partialFilename]['status'] !== $newStatus) {
                                // Update the status of the matched attachment
                                $attachments[$emailId]['attachments'][$partialFilename]['status'] = $newStatus;
                                echo "Attachment status updated successfully for the filename $fullFilename." . PHP_EOL."<br>";
                            } else {
                                echo "Already processed for the filename $fullFilename." . PHP_EOL."<br>";
                            }
                            break; // Stop searching after the first match is found
                        } else{
                            echo "Wrong attachments for $fullFilename." . PHP_EOL."<br>";
                        }
                    }
                }
            }
        }

        // Save the updated attachment information back to the JSON file
        file_put_contents($this->processedEmailsJson, json_encode($attachments, JSON_PRETTY_PRINT));
    }
}


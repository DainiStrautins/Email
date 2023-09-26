<?php

namespace Email\Controller;

/**
 * Class EmailAttachmentDownloader
 *
 * This class handles the download and storage of email attachments.
 *
 * @package MyEmailLibrary\Attachment
 */
class EmailAttachmentDownloader {

    /** @var string $outputDirectory The directory where attachments are saved. */
    private string $outputDirectory = __DIR__ . '/../excels/';

    /**
     * Download and save email attachments.
     *
     * This method processes and saves email attachments to the specified output directory.
     *
     * @param array $emailContent An array containing attachment data.
     *
     * @return void
     *
     */
    public function downloadAttachments(array $emailContent): void
    {

        // Create the output directory if it doesn't exist
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0755, true);
        }

        foreach ($emailContent as $key => $attachment) {
            // Construct the full path to save the attachment
            $outputPath = __DIR__ . '/../../excels/';

            // Save the attachment and handle the result
            $result = $this->saveAttachment($attachment, $outputPath,$key);

            if ($result === 'success') {
                $this->log("Attachment saved: $outputPath");
            } elseif ($result === 'failure') {
                $this->log("Error saving attachment: $outputPath");
            } elseif ($result === 'exists') {
                $this->log("Attachment already exists: $outputPath");
            }
        }
    }

    /**
     * Log a message.
     *
     * This method logs a message to the standard output.
     *
     * @param string $message The message to be logged.
     *
     * @return void
     */
    private function log(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * Save an attachment to the output directory.
     *
     * This method saves an attachment to the specified output directory.
     *
     * @param array $attachment       An array containing attachment data.
     * @param string $outputDirectory The directory where the attachment is saved.
     * @param string $attachmentHash  The unique hash of the attachment.
     *
     * @return string The result of the attachment-saving process.
     *
     */
    private function saveAttachment(array $attachment, string $outputDirectory, string $attachmentHash): string
    {
        // Extract the original filename and extension
        $originalFilename = $attachment['filename'];
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        // Construct the full path to save the attachment
        $outputPath = $outputDirectory . DIRECTORY_SEPARATOR . $attachmentHash . '.' . $extension;


        // Check if the file already exists
        if (file_exists($outputPath)) {
            return 'exists';
        }

        // Decode the base64 attachment content and save it to the output directory
        $attachmentContent = $attachment['raw'];

        if (file_put_contents($outputPath, $attachmentContent) !== false) {
            return 'success';
        } else {
            return 'failure';
        }
    }

}


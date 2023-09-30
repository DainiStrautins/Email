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


    /**
     * Download and save email attachments.
     *
     * This method processes and saves email attachments to the specified output directory.
     *
     * @param array $emailContent An array containing attachment data.
     * @param string $outputPath The directory where the attachments will be saved.
     * @param bool $overwrite Whether to overwrite existing files (default is false).
     *
     * @return void
     */
    public function downloadAttachments(array $emailContent, $outputPath, ?bool $overwrite = false): void
    {
        // Ensure the output path ends with a directory separator
        $outputPath = rtrim($outputPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach ($emailContent as $attachment) {
            // Save the attachment and handle the result
            $result = $this->saveAttachment($attachment, $outputPath, $overwrite);

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
     * @param array $attachment An array containing attachment data.
     * @param string $outputDirectory The directory where the attachment is saved.
     * @param bool $overwrite Whether to overwrite existing files.
     *
     * @return string The result of the attachment-saving process.
     */
    private function saveAttachment(array $attachment, string $outputDirectory, bool $overwrite): string
    {
        // Construct the full path to save the attachment
        $outputPath = $outputDirectory . DIRECTORY_SEPARATOR . $attachment['filename'];

        // Check if the file already exists and $overwrite is false
        if (!$overwrite && file_exists($outputPath)) {
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


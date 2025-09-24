<?php

declare(strict_types=1);

namespace PP\PHPMailer;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PP\Validator;

class Mailer
{
    private PHPMailer $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        $this->mail->CharSet = 'UTF-8';
        $this->setup();
    }

    private function setup(): void
    {
        $this->mail->isSMTP();
        $this->mail->SMTPDebug = 0;
        $this->mail->Host = $_ENV['SMTP_HOST'];
        $this->mail->SMTPAuth = true;
        $this->mail->Username = $_ENV['SMTP_USERNAME'];
        $this->mail->Password = $_ENV['SMTP_PASSWORD'];
        $this->mail->SMTPSecure = $_ENV['SMTP_ENCRYPTION'];
        $this->mail->Port = (int) $_ENV['SMTP_PORT'];
        $this->mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']);
    }

    /**
     * Send an email.
     *
     * @param string $to The recipient's email address.
     * @param string $subject The subject of the email.
     * @param string $body The HTML body of the email.
     * @param array $options (optional) Additional email options like name, altBody, CC, BCC, and attachments.
     *                       - attachments: A string or an array of file paths, or an array of associative arrays with keys 'path' and 'name'.
     *
     * @return bool Returns true if the email is sent successfully, false otherwise.
     *
     * @throws Exception Throws an exception if the email could not be sent.
     */
    public function send(string $to, string $subject, string $body, array $options = []): bool
    {
        try {
            // Validate and sanitize inputs
            $to = Validator::email($to);
            if (!$to) {
                throw new Exception('Invalid email address for the main recipient');
            }

            $subject = Validator::string($subject);
            $body = Validator::html($body);
            $altBody = $this->convertToPlainText($body);

            $name = $options['name'] ?? '';
            $addCC = $options['addCC'] ?? [];
            $addBCC = $options['addBCC'] ?? [];
            $attachments = $options['attachments'] ?? [];

            $name = Validator::string($name);

            // Handle CC recipients
            $this->handleRecipients($addCC, 'CC');
            // Handle BCC recipients
            $this->handleRecipients($addBCC, 'BCC');
            // Handle file attachments if provided
            if (!empty($attachments)) {
                $this->handleAttachments($attachments);
            }

            // Set the main recipient and other email properties
            $this->mail->addAddress($to, $name);
            $this->mail->isHTML(true);
            $this->mail->Subject = $subject;
            $this->mail->Body = $body;
            $this->mail->AltBody = $altBody;

            // Send the email
            return $this->mail->send();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Handle adding CC or BCC recipients.
     *
     * @param string|array $recipients Email addresses to add.
     * @param string $type Type of recipient ('CC' or 'BCC').
     *
     * @throws Exception Throws an exception if any email address is invalid.
     */
    private function handleRecipients(string|array $recipients, string $type): void
    {
        if (!empty($recipients)) {
            $method = $type === 'CC' ? 'addCC' : 'addBCC';

            if (is_array($recipients)) {
                foreach ($recipients as $recipient) {
                    $recipient = Validator::email($recipient);
                    if ($recipient) {
                        $this->mail->{$method}($recipient);
                    } else {
                        throw new Exception("Invalid email address in $type");
                    }
                }
            } else {
                $recipient = Validator::email($recipients);
                if ($recipient) {
                    $this->mail->{$method}($recipient);
                } else {
                    throw new Exception("Invalid email address in $type");
                }
            }
        }
    }

    /**
     * Handle adding file attachments.
     *
     * @param string|array $attachments File path(s) to attach.
     *                                  You can pass a string for a single file or an array of file paths.
     *                                  Alternatively, each attachment can be an array with keys 'path' and 'name' for custom naming.
     *
     * @throws Exception Throws an exception if any attachment file is not found.
     */
    private function handleAttachments(string|array $attachments): void
    {
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (is_array($attachment)) {
                    $file = $attachment['path'] ?? null;
                    $name = $attachment['name'] ?? '';
                    if (!$file || !file_exists($file)) {
                        throw new Exception("Attachment file does not exist: " . ($file ?? 'unknown'));
                    }
                    $this->mail->addAttachment($file, $name);
                } else {
                    if (!file_exists($attachment)) {
                        throw new Exception("Attachment file does not exist: $attachment");
                    }
                    $this->mail->addAttachment($attachment);
                }
            }
        } else {
            if (!file_exists($attachments)) {
                throw new Exception("Attachment file does not exist: $attachments");
            }
            $this->mail->addAttachment($attachments);
        }
    }

    /**
     * Convert HTML content to plain text.
     *
     * @param string $html The HTML content to convert.
     * @return string The plain text content.
     */
    private function convertToPlainText(string $html): string
    {
        return strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html));
    }
}

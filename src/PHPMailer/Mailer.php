<?php

declare(strict_types=1);

namespace PP\PHPMailer;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PP\Validator;

class Mailer
{
    private PHPMailer $mail;
    private bool $messageDirty = false;

    public function __construct(?PHPMailer $mail = null)
    {
        $this->mail = $mail ?? new PHPMailer(true);
        $this->mail->CharSet = 'UTF-8';

        $this->configureTransport();
        $this->configureDefaultFrom();
    }

    private function configureTransport(): void
    {
        $this->mail->isSMTP();
        $this->mail->SMTPDebug  = 0;
        $this->mail->Host       = $_ENV['SMTP_HOST']       ?? '';
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = $_ENV['SMTP_USERNAME']   ?? '';
        $this->mail->Password   = $_ENV['SMTP_PASSWORD']   ?? '';
        $this->mail->SMTPSecure = $_ENV['SMTP_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $this->mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);
    }

    private function configureDefaultFrom(): void
    {
        $from     = $_ENV['MAIL_FROM']      ?? null;
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? '';

        if ($from) {
            $email = Validator::email($from);
            if ($email) {
                $this->mail->setFrom($email, Validator::string($fromName));
            }
        }
    }

    /**
     * Override the "from" address for this message.
     *
     * @param string      $email Sender email.
     * @param string|null $name  Optional sender name.
     *
     * @return $this
     *
     * @throws Exception If email is invalid.
     */
    public function from(string $email, ?string $name = null): self
    {
        $this->touchMessage();

        $email = Validator::email($email);
        if (!$email) {
            throw new Exception('Invalid "from" email address');
        }

        $name = $name !== null ? Validator::string($name) : '';
        $this->mail->setFrom($email, $name);

        return $this;
    }

    /**
     * Add a main recipient.
     *
     * @param string      $email Recipient email.
     * @param string|null $name  Optional recipient name.
     *
     * @return $this
     *
     * @throws Exception If email is invalid.
     */
    public function to(string $email, ?string $name = null): self
    {
        $this->touchMessage();

        $email = Validator::email($email);
        if (!$email) {
            throw new Exception('Invalid "to" email address');
        }

        $name = $name !== null ? Validator::string($name) : '';
        $this->mail->addAddress($email, $name);

        return $this;
    }

    /**
     * Add a CC recipient.
     *
     * @param string      $email CC email.
     * @param string|null $name  Optional CC name.
     *
     * @return $this
     *
     * @throws Exception If email is invalid.
     */
    public function cc(string $email, ?string $name = null): self
    {
        $this->touchMessage();

        $email = Validator::email($email);
        if (!$email) {
            throw new Exception('Invalid "cc" email address');
        }

        $name = $name !== null ? Validator::string($name) : '';
        $this->mail->addCC($email, $name);

        return $this;
    }

    /**
     * Add a BCC recipient.
     *
     * @param string      $email BCC email.
     * @param string|null $name  Optional BCC name.
     *
     * @return $this
     *
     * @throws Exception If email is invalid.
     */
    public function bcc(string $email, ?string $name = null): self
    {
        $this->touchMessage();

        $email = Validator::email($email);
        if (!$email) {
            throw new Exception('Invalid "bcc" email address');
        }

        $name = $name !== null ? Validator::string($name) : '';
        $this->mail->addBCC($email, $name);

        return $this;
    }

    /**
     * Set Reply-To address.
     *
     * @param string      $email Reply-to email.
     * @param string|null $name  Optional reply-to name.
     *
     * @return $this
     *
     * @throws Exception If email is invalid.
     */
    public function replyTo(string $email, ?string $name = null): self
    {
        $this->touchMessage();

        $email = Validator::email($email);
        if (!$email) {
            throw new Exception('Invalid "reply-to" email address');
        }

        $name = $name !== null ? Validator::string($name) : '';
        $this->mail->addReplyTo($email, $name);

        return $this;
    }

    /**
     * Set the subject line.
     *
     * @param string $subject Subject text.
     *
     * @return $this
     */
    public function subject(string $subject): self
    {
        $this->touchMessage();

        $this->mail->Subject = Validator::string($subject);

        return $this;
    }

    /**
     * Set HTML body (and optional plain-text alternative).
     *
     * @param string      $html    HTML content.
     * @param string|null $altText Optional plain-text body. If null, generated from HTML.
     *
     * @return $this
     */
    public function html(string $html, ?string $altText = null): self
    {
        $this->touchMessage();

        $this->mail->isHTML(true);
        $this->mail->Body    = $html;
        $this->mail->AltBody = $altText ?? $this->convertToPlainText($html);

        return $this;
    }

    /**
     * Set a plain-text-only body.
     *
     * @param string $text Plain-text body.
     *
     * @return $this
     */
    public function text(string $text): self
    {
        $this->touchMessage();

        $text = Validator::string($text);
        $this->mail->isHTML(false);
        $this->mail->Body    = $text;
        $this->mail->AltBody = $text;

        return $this;
    }

    /**
     * Attach a single file.
     *
     * @param string      $path File path.
     * @param string|null $name Optional attachment name.
     *
     * @return $this
     *
     * @throws Exception If file does not exist.
     */
    public function attach(string $path, ?string $name = null): self
    {
        $this->touchMessage();

        if (!file_exists($path)) {
            throw new Exception("Attachment file does not exist: {$path}");
        }

        $this->mail->addAttachment($path, $name ?? '');

        return $this;
    }

    /**
     * Attach multiple files.
     *
     * @param array<int,string|array{path:string,name?:string|null}> $attachments
     *
     * @return $this
     *
     * @throws Exception If any file does not exist.
     */
    public function attachMany(array $attachments): self
    {
        foreach ($attachments as $attachment) {
            if (is_array($attachment)) {
                $path = $attachment['path'] ?? null;
                $name = $attachment['name'] ?? null;

                if (!$path || !file_exists($path)) {
                    throw new Exception('Attachment file does not exist: ' . ($path ?? 'unknown'));
                }

                $this->mail->addAttachment($path, $name ?? '');
                continue;
            }

            $this->attach($attachment);
        }

        return $this;
    }

    /**
     * Send the composed email.
     *
     * @return bool True on success, false otherwise.
     *
     * @throws Exception If sending fails.
     */
    public function send(): bool
    {
        try {
            $sent = $this->mail->send();
            $this->resetMessage();
            $this->messageDirty = false;

            return $sent;
        } catch (Exception $e) {
            $this->resetMessage();
            $this->messageDirty = false;

            throw new Exception('Mail could not be sent: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the underlying PHPMailer instance for low-level configuration.
     *
     * @return PHPMailer
     */
    public function raw(): PHPMailer
    {
        return $this->mail;
    }

    private function touchMessage(): void
    {
        if (!$this->messageDirty) {
            $this->resetMessage();
            $this->messageDirty = true;
        }
    }

    private function resetMessage(): void
    {
        $this->mail->clearAllRecipients();
        $this->mail->clearAttachments();

        $this->mail->Subject = '';
        $this->mail->Body    = '';
        $this->mail->AltBody = '';
    }

    private function convertToPlainText(string $html): string
    {
        $text = str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>'],
            "\n",
            $html
        );

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}

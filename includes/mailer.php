<?php
/**
 * File: includes/mailer.php
 * Folder: includes/
 * Purpose: A minimal, hand-written SMTP client for sending plain
 *          password-reset emails. Written from scratch using PHP's
 *          built-in socket functions (fsockopen + stream_socket_enable_crypto)
 *          — deliberately NOT using PHPMailer or any Composer package,
 *          per the project's "no external libraries" rule.
 *
 *          Supports the common case: SMTP with STARTTLS on port 587,
 *          authenticated with AUTH LOGIN (what Gmail, Mailtrap, and most
 *          providers expect). This is not a full RFC-5321 implementation
 *          — it's enough for one plain-text email at a time, which is
 *          all this project needs.
 */

require_once __DIR__ . '/../config/mail.php';

/**
 * Sends a plain-text email via SMTP. Returns true on success, false on
 * any failure (connection, auth, or send error) — never throws, so the
 * calling page can show a generic message either way.
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): bool
{
    $subject = 'Reset your Smart Rental password';
    $body = "Hi {$toName},\r\n\r\n"
        . "We received a request to reset your Smart Rental password.\r\n\r\n"
        . "Click this link to set a new password (valid for 30 minutes):\r\n"
        . "{$resetLink}\r\n\r\n"
        . "If you didn't request this, you can safely ignore this email.\r\n\r\n"
        . "— Smart Rental System\r\n";

    return smtpSend($toEmail, $subject, $body);
}

function smtpSend(string $toEmail, string $subject, string $body): bool
{
    $socket = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 10);
    if (!$socket) {
        error_log("Mailer: could not connect to SMTP server — $errstr ($errno)");
        return false;
    }

    try {
        if (!smtpExpect($socket, '220')) return false;

        smtpCommand($socket, "EHLO localhost\r\n");
        if (!smtpExpect($socket, '250')) return false;

        smtpCommand($socket, "STARTTLS\r\n");
        if (!smtpExpect($socket, '220')) return false;

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('Mailer: TLS negotiation failed.');
            return false;
        }

        // Must re-EHLO after STARTTLS per the SMTP spec.
        smtpCommand($socket, "EHLO localhost\r\n");
        if (!smtpExpect($socket, '250')) return false;

        smtpCommand($socket, "AUTH LOGIN\r\n");
        if (!smtpExpect($socket, '334')) return false;

        smtpCommand($socket, base64_encode(SMTP_USERNAME) . "\r\n");
        if (!smtpExpect($socket, '334')) return false;

        smtpCommand($socket, base64_encode(SMTP_PASSWORD) . "\r\n");
        if (!smtpExpect($socket, '235')) {
            error_log('Mailer: SMTP authentication failed — check config/mail.php credentials.');
            return false;
        }

        smtpCommand($socket, 'MAIL FROM:<' . SMTP_FROM_EMAIL . ">\r\n");
        if (!smtpExpect($socket, '250')) return false;

        smtpCommand($socket, "RCPT TO:<{$toEmail}>\r\n");
        if (!smtpExpect($socket, '250')) return false;

        smtpCommand($socket, "DATA\r\n");
        if (!smtpExpect($socket, '354')) return false;

        $headers = "From: " . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . ">\r\n";
        $headers .= "To: <{$toEmail}>\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "\r\n";

        // Per SMTP spec, lines starting with a lone "." must be escaped.
        $escapedBody = str_replace("\r\n.", "\r\n..", $body);

        smtpCommand($socket, $headers . $escapedBody . "\r\n.\r\n");
        if (!smtpExpect($socket, '250')) return false;

        smtpCommand($socket, "QUIT\r\n");
        fclose($socket);

        return true;

    } catch (\Throwable $e) {
        error_log('Mailer: unexpected error — ' . $e->getMessage());
        if (is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
}

function smtpCommand($socket, string $command): void
{
    fwrite($socket, $command);
}

function smtpExpect($socket, string $expectedCode): bool
{
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        // Multi-line SMTP responses have a hyphen after the code on all
        // but the last line (e.g. "250-" ... "250 "). Stop once we hit
        // a line with a space after the code.
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    if (strpos($response, $expectedCode) !== 0) {
        error_log("Mailer: expected {$expectedCode}, got: {$response}");
        return false;
    }
    return true;
}
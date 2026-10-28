<?php

/**
 * 1. Relative path: api/mail.php
 * 2. Description: SMTP mail wrapper around the vendored PHPMailer ("shoulders of giants").
 *                 Real SMTP credentials live in config/mail.local.php (git-ignored);
 *                 config/mail.php is the template. Mail features off when unconfigured.
 * 3. Expects: PHP file: ['smtp' => ['host','port','user','pass','secure'], 'from' => ['email','name']];
 * 4. Provides: enabled(), send(string $to, string $subject, string $bodyPlain).
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Mail;

require_once __DIR__ . '/lib/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/phpmailer/src/SMTP.php';
require_once __DIR__ . '/lib/phpmailer/src/Exception.php';

function templatePath(): string
{
    return __DIR__ . '/config/mail.php';
}

function realPath(): string
{
    return __DIR__ . '/config/mail.local.php';
}

/** @return array<string, mixed> The active config (real file wins over template); empty array when unreadable. */
function configPath(): array
{
    $local = __DIR__ . '/config/mail.local.php';
    $path = is_file($local) ? $local : templatePath();
    try {
        $c = require $path;
    } catch (\Throwable) {
        $c = [];
    }
    return is_array($c) ? $c : [];
}

function enabled(): bool
{
    $c = configPath();
    $host = $c['smtp']['host'] ?? null;
    return is_string($host) && $host !== '';
}

/** @return never Logs the failure to the server error log and refuses the request. */
function refuseMail(string $why): never
{
    @error_log("[schmarrn-mail] {$why}");
    \Router\refuse(502, 'Mail delivery failed — ask the server log, sugar.');
}

function send(string $to, string $subject, string $bodyPlain): void
{
    $c = configPath();
    if (!enabled()) {
        \Router\refuse(503, 'Mail not configured.');
    }
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = (string)($c['smtp']['host'] ?? '');
        $mailer->Port = (int)($c['smtp']['port'] ?? 587);
        $secure = (string)($c['smtp']['secure'] ?? 'tls');
        $mailer->SMTPAuth = ($c['smtp']['user'] ?? '') !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = (string)($c['smtp']['user'] ?? '');
            $mailer->Password = (string)($c['smtp']['pass'] ?? '');
        }
        $mailer->SMTPSecure = $secure === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom((string)($c['from']['email'] ?? $mailer->Username), (string)($c['from']['name'] ?? 'Schmarrn'));
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->Body = $bodyPlain;
        $mailer->send();
    } catch (\Throwable $e) {
        // Never leak SMTP exceptions to the client — log, refuse loudly here.
        refuseMail('send failed: ' . $e->getMessage());
    }
}

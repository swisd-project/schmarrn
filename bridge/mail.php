<?php

/**
 * 1. Relative path: bridge/mail.php
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
require_once __DIR__ . '/vault.php'; // the vault rides every mail load — the SMTP password rests sealed

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
    throw new \Domain\Error(502, 'Mail delivery failed — ask the server log, sugar.');
}

/**
 * The Integrations card's view (2026-11-12, user order): what is plumbed in,
 * minus the password — the admin sees the shape, never the secret.
 */
function status(): array
{
    $c = configPath();
    $host = (string)($c['smtp']['host'] ?? '');
    return [
        'configured' => $host !== '',
        'host' => $host,
        'port' => (int)($c['smtp']['port'] ?? 587),
        'secure' => (string)($c['smtp']['secure'] ?? 'tls'),
        'fromEmail' => (string)($c['from']['email'] ?? ''),
        'fromName' => (string)($c['from']['name'] ?? 'Schmarrn'),
    ];
}

/** Validate a candidate config from the dashboard — junk is refused, not stored. */
function normalize(array $body): array
{
    $host = trim((string)($body['host'] ?? ''));
    $port = (int)($body['port'] ?? 587);
    $secure = (string)($body['secure'] ?? 'tls');
    $fromEmail = trim((string)($body['fromEmail'] ?? ''));
    if ($host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false && filter_var($host, FILTER_VALIDATE_IP) === false) {
        throw new \Domain\Error(400, 'That SMTP host does not parse as a hostname.');
    }
    if ($port < 1 || $port > 65535) {
        throw new \Domain\Error(400, 'That port is not a door — 1 to 65535.');
    }
    if ($secure !== 'tls' && $secure !== 'ssl' && $secure !== 'none') {
        throw new \Domain\Error(400, 'Secure must be tls, ssl or none.');
    }
    if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new \Domain\Error(400, 'That sender address is half-baked — no proper email in the dough.');
    }
    return [
        'host' => $host,
        'port' => $port,
        'user' => trim((string)($body['user'] ?? '')),
        'pass' => (string)($body['pass'] ?? ''),
        'secure' => $secure,
        'fromEmail' => $fromEmail,
        'fromName' => trim((string)($body['fromName'] ?? 'Schmarrn')),
    ];
}

/**
 * TEST a candidate config without keeping it (the modal's "Test connection"):
 * open the SMTP line, upgrade TLS when asked, authenticate — send nothing.
 * The credentials live for one call and die, like the GitHub token test.
 */
function test(array $c): array
{
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = $c['host'];
        $mailer->Port = $c['port'];
        $mailer->SMTPAuth = $c['user'] !== '';
        $mailer->Username = $c['user'];
        $mailer->Password = $c['pass'];
        $mailer->SMTPSecure = $c['secure'] === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : ($c['secure'] === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        $mailer->Timeout = 10;
        if (!$mailer->smtpConnect()) {
            return ['ok' => false, 'error' => 'The SMTP door did not open — host, port or encryption refused the line.'];
        }
        if ($mailer->SMTPAuth && !$mailer->getSMTPInstance()->authenticate($c['user'], $c['pass'])) {
            return ['ok' => false, 'error' => 'The SMTP door opened but the key did not turn — check user and password.'];
        }
        $mailer->smtpClose();
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'The SMTP line refused: ' . $e->getMessage()];
    }
}

/** Remember the plumbed-in oven — the PASSWORD rests sealed in the house vault;
 *  the config file carries the reference and the non-secret shape. A legacy
 *  plaintext pass in the config dies the moment a new one is sealed. */
function save(array $c): void
{
    \Vault\seal('smtp.pass', $c['pass']);
    $dir = __DIR__ . '/config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = realPath();
    @file_put_contents($file, "<?php\n// SMTP account — written by the dashboard's Integrations card. The password rests sealed in the vault.\nreturn " . var_export([
        'smtp' => [
            'host' => $c['host'],
            'port' => $c['port'],
            'user' => $c['user'],
            'passVault' => 'smtp.pass',
            'secure' => $c['secure'],
        ],
        'from' => [
            'email' => $c['fromEmail'],
            'name' => $c['fromName'],
        ],
    ], true) . ";\n");
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
}

/** Let the oven go — the local file leaves, the empty template answers, mail is off. */
function disconnect(): void
{
    \Vault\forget('smtp.pass'); // the envelope dies with the plumbing
    $file = realPath();
    if (is_file($file)) {
        @unlink($file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}


/**
 * The SMTP password — the vault envelope first; the pre-vault plaintext in the
 * config file still answers (legacy rigs keep sending).
 */
function pass(): string
{
    $c = configPath();
    $sealed = \Vault\open('smtp.pass');
    if ($sealed !== null) {
        return $sealed;
    }
    return (string)($c['smtp']['pass'] ?? '');
}

function send(string $to, string $subject, string $bodyPlain): void
{
    $c = configPath();
    if (!enabled()) {
        throw new \Domain\Error(503, 'The mail oven is not plumbed in — SMTP not configured.');
    }
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = (string)($c['smtp']['host'] ?? '');
        $mailer->Port = (int)($c['smtp']['port'] ?? 587);
        $secure = (string)($c['smtp']['secure'] ?? 'tls');
        $mailer->SMTPSecure = $secure === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : ($secure === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        $mailer->SMTPAuth = ($c['smtp']['user'] ?? '') !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = (string)($c['smtp']['user'] ?? '');
            $mailer->Password = pass();
        }
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

<?php

/**
 * 1. Relative path: bridge/vault.php
 * 2. Description: The house vault (2026-11-12, user order) — one place where
 *                 secrets rest ENCRYPTED, never plainly. The master key is the
 *                 vault's own lock: a 32-byte random key in a 0600 keyfile
 *                 (git-ignored), minted on first use. Every secret is sealed
 *                 with libsodium's crypto_secretbox (in PHP's core — no
 *                 dependency), its own random nonce, never reused.
 *                 Threat model, honestly: the keyfile sits beside the vault,
 *                 so a root-level reader opens both — the vault's wage is that
 *                 a leak of the config FILES alone (backup, accidental commit,
 *                 directory listing) yields no secrets, and PHP never holds
 *                 secrets in plaintext files it can serve.
 * 3. Expects: sodium extension (PHP core since 7.2).
 * 4. Provides: Vault\seal(string $name, string $secret): void
 *              Vault\open(string $name): ?string  (null when absent/corrupt — fails closed)
 *              Vault\forget(string $name): void
 *              Vault\holds(): array<string>       (names only, never values)
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Vault;

function dirPath(): string
{
    return __DIR__ . '/config';
}

function keyFile(): string
{
    return dirPath() . '/vault.key';
}

function file(): string
{
    return dirPath() . '/vault.local.php';
}

/**
 * The master key — minted once, kept in a 0600 keyfile the house can read.
 * Generation failure is a loud refusal, never a silent downgrade to plaintext.
 */
function key(): string
{
    $file = keyFile();
    if (is_file($file)) {
        $raw = (string)@file_get_contents($file);
        if (preg_match('/^[0-9a-f]{64}$/', $raw) === 1) {
            return $raw;
        }
        throw new \Domain\Error(500, 'The vault key is corrupt — restore vault.key from backup; no secret will open by force.');
    }
    $fresh = bin2hex(random_bytes(32)); // hex so any editor/backup survives it intact
    if (!is_dir(dirPath())) {
        @mkdir(dirPath(), 0775, true);
    }
    if (@file_put_contents($file, $fresh) === false || !@chmod($file, 0600)) {
        throw new \Domain\Error(500, 'The vault key could not be minted — check the config directory\'s writability.');
    }
    return $fresh;
}

/** The sealed shelf — names and their envelopes, nothing more. */
function shelf(): array
{
    if (!is_file(file())) {
        return [];
    }
    $data = null;
    try {
        $data = require file();
    } catch (\Throwable) {
        $data = null;
    }
    return is_array($data) ? $data : [];
}

function write(array $shelf): void
{
    if (!is_dir(dirPath())) {
        @mkdir(dirPath(), 0775, true);
    }
    @file_put_contents(file(), "<?php\n// The house vault — sealed envelopes only. The master key lives in vault.key (0600).\nreturn " . var_export($shelf, true) . ";\n");
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(file(), true);
    }
}

/** Seal a secret under a name — fresh nonce every time, the old envelope dies. */
function seal(string $name, string $secret): void
{
    if ($name === '' || preg_match('/^[a-z][a-z0-9.]{0,63}$/', $name) !== 1) {
        throw new \Domain\Error(400, 'That vault name does not parse — letters, dots, digits.');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $sealed = sodium_crypto_secretbox($secret, $nonce, sodium_hex2bin(key()));
    $shelf = shelf();
    $shelf[$name] = ['n' => sodium_bin2hex($nonce), 'c' => sodium_bin2hex($sealed)];
    write($shelf);
}

/** Open a name — null when absent or tampered; the caller decides what a dark shelf means. */
function open(string $name): ?string
{
    $envelope = shelf()[$name] ?? null;
    if (!is_array($envelope) || !is_string($envelope['n'] ?? null) || !is_string($envelope['c'] ?? null)) {
        return null;
    }
    $plain = @sodium_crypto_secretbox_open(sodium_hex2bin($envelope['c']), sodium_hex2bin($envelope['n']), sodium_hex2bin(key()));
    return is_string($plain) ? $plain : null;
}

/** Take a name off the shelf. */
function forget(string $name): void
{
    $shelf = shelf();
    if (array_key_exists($name, $shelf)) {
        unset($shelf[$name]);
        write($shelf);
    }
}

/** The names on the shelf — a manifest, never the goods. */
function holds(): array
{
    return array_keys(shelf());
}

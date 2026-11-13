<?php

/**
 * 1. Relative path: tests/core-update-battery.php
 * 2. Description: THE CORE-UPDATE BATTERY — the proof that a core self-update
 *                 carries the WHOLE core and NOTHING else. The staging law
 *                 (isCore) is the whitelist between a release packet and a
 *                 live host: it must carry the p8 rings (bridge/domain/,
 *                 bridge/application/) — an update that dropped them would
 *                 brick every host, since the libs throw Domain\Error by
 *                 import — and it must REFUSE the non-core land (plugins,
 *                 templates, content, *.local.php, the cache) no matter what
 *                 the packet smuggles. Three parts: isCore's word, the real
 *                 zip through stageCore, and a FULL local ride on a rig —
 *                 packet parked, knife out, swap done, site still serves.
 * 3. Expects: The repo root (dirname __DIR__). ZipArchive for the packet.
 * 4. Provides: The verdict; exit 0 green, 1 red. Skips cleanly when the
 *              zip machinery is absent.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

$root = dirname(__DIR__);

if (!class_exists('ZipArchive') && !extension_loaded('phar')) {
    echo "SKIP  no zip machinery on this host — the update cycle cannot ride here anyway\n";
    exit(0);
}

require_once $root . '/bridge/core.php';

/** Build a packet zip the way the core can read it (PharData — the same
 *  engine stageCore's fallback uses; ZipArchive is absent on this CLI). */
function buildPacket(string $dest, string $top, array $files): void
{
    @unlink($dest);
    $phar = new \PharData($dest, \FilesystemIterator::SKIP_DOTS);
    foreach ($files as $rel => $body) {
        $phar->addFromString("{$top}/{$rel}", $body);
    }
}

// ——— 1. isCore's word: the rings are core, the smuggle is not ———
Harness::check('the domain ring is core', \Core\isCore('bridge/domain/error.php'));
Harness::check('the application ring is core (nested)', \Core\isCore('bridge/application/commands/page.php'));
Harness::check('the application ring is core (queries)', \Core\isCore('bridge/application/queries/page.php'));
Harness::check('a flat bridge module is still core', \Core\isCore('bridge/store.php'));
Harness::check('the wholesale dirs are still core', \Core\isCore('build/_app/x.js') && \Core\isCore('bridge/lib/phpmailer/x.php'));
Harness::check('a pagetype shell is still core', \Core\isCore('pagetypes/blank.md'));
Harness::check('a plugin is never core', !\Core\isCore('bridge/plugins/evil/plugin.php'));
Harness::check('a template is never core', !\Core\isCore('bridge/templates/bakery/page.php'));
Harness::check('a LOCAL config is never core', !\Core\isCore('bridge/config/accounts.local.php'));
Harness::check('content is never core', !\Core\isCore('src/content/pages/hello/index.md'));
Harness::check('the cache is never core', !\Core\isCore('bridge/cache/junk.txt'));

// ——— 2. stageCore: the real packet — only the whitelist crosses ———
$scratch = sys_get_temp_dir() . '/core-update-battery-' . getmypid();
@mkdir($scratch, 0775, true);

$packet = $scratch . '/packet.zip';
$top = 'schmarrn-v9.9.9';
buildPacket($packet, $top, [
    // the core land
    'index.php' => "<?php // the packet's index\n",
    '.htaccess' => "# the packet's htaccess\n",
    'bridge/CORE-VERSION' => "9.9.9\n",
    'bridge/store.php' => "<?php\n// packet store\n",
    'bridge/domain/error.php' => "<?php\n// packet domain\n",
    'bridge/application/commands/page.php' => "<?php\n// packet commands\n",
    'bridge/config/site.php' => "<?php\n// packet config default\n",
    'build/index.html' => "<!doctype html>the packet's dashboard\n",
    'pagetypes/blank.md' => "# the packet's shell\n",
    // the smuggle — every one of these must STAY OUTSIDE
    'bridge/plugins/evil/plugin.php' => "<?php // smuggled plugin\n",
    'bridge/templates/evil.php' => "<?php // smuggled template\n",
    'bridge/config/evil.local.php' => "<?php // smuggled LOCAL config\n",
    'src/content/pages/hello/index.md' => "HACKED\n",
    'bridge/cache/junk.txt' => "junk\n",
]);

[$staged, $entries, $bytes] = \Core\stageCore($packet, $scratch);
Harness::check('the packet opens and stages', is_string($staged), 'stageCore answered ' . var_export($staged, true));
if (is_string($staged)) {
    // the extraction keeps the packet's top dir (swap() globs for it) — walk
    // under it, exactly the way swap() finds the repo-relative land
    $stagedTop = $staged;
    foreach ((array)glob($staged . '/*', GLOB_ONLYDIR) ?: [] as $candidate) {
        $stagedTop = (string)$candidate;
        break;
    }
    $stagedRel = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stagedTop, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $stagedRel[] = substr(str_replace('\\', '/', (string)$file), strlen($stagedTop) + 1);
        }
    }
    sort($stagedRel);
    $expected = [
        '.htaccess', 'bridge/CORE-VERSION', 'bridge/application/commands/page.php',
        'bridge/config/site.php', 'bridge/domain/error.php', 'bridge/store.php',
        'build/index.html', 'index.php', 'pagetypes/blank.md',
    ];
    sort($expected);
    Harness::check('the staging carries EXACTLY the core land', $stagedRel === $expected,
        "staged:\n    " . implode("\n    ", $stagedRel));
    Harness::check('the smuggled plugin stayed outside', !is_file($stagedTop . '/bridge/plugins/evil/plugin.php'));
    Harness::check('the smuggled LOCAL config stayed outside', !is_file($stagedTop . '/bridge/config/evil.local.php'));
    Harness::check('the smuggled content stayed outside', !is_file($stagedTop . '/src/content/pages/hello/index.md'));
    Harness::check('the smuggled template stayed outside', !is_file($stagedTop . '/bridge/templates/evil.php'));
    Harness::check('the ring file rode whole (bytes intact)',
        is_file($stagedTop . '/bridge/domain/error.php')
        && str_contains((string)file_get_contents($stagedTop . '/bridge/domain/error.php'), 'packet domain'));
    \Core\partWith($scratch); // clears $scratch — the staging with it
}

// ——— 3. the FULL local ride on a rig — the knife comes out for real ———
// The update must run INSIDE the rig (core.php's constants bind to __DIR__ —
// calling it from this process would point the knife at the real tree), so
// the battery drops a runner script into the rig and executes it there.
require_once __DIR__ . '/rig.php';
$rig = rigBuild($root);
$rigVer = trim((string)file_get_contents($rig . '/bridge/CORE-VERSION'));
$to = '9.9.9'; // strictly above any real version — the local gate demands it

// the packet = the rig's own tree, CORE-VERSION bumped, plus a decoy set the
// whitelist must refuse (an evil plugin, an evil LOCAL config, HACKED content)
$packet = $scratch . '/ride-packet.zip';
$rideFiles = [];
$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rig, \FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel = substr(str_replace('\\', '/', (string)$file), strlen($rig) + 1);
    if (str_starts_with($rel, 'bridge/cache/')) {
        continue; // the cache is scratch, never packet
    }
    $rideFiles[$rel] = (string)file_get_contents((string)$file);
}
$rideFiles['bridge/CORE-VERSION'] = "{$to}\n";
$rideFiles['build/index.html'] = "<!doctype html>the ride's dashboard\n";
$rideFiles['pagetypes/extra.md'] = "# a NEW shell — the merge law lands it\n";
$rideFiles['bridge/plugins/evil/plugin.php'] = "<?php // the smuggle\n";
$rideFiles['bridge/config/accounts.local.php'] = "<?php return ['evil' => []];\n";
$rideFiles['src/content/pages/hello/index.md'] = "HACKED\n";
buildPacket($packet, $top, $rideFiles);
copy($packet, $rig . '/bridge/cache/core-packet.zip'); // park it where LOCAL_PACKET looks

// the page the ride must NOT touch
rigWritePage($rig, 'hello', 'Hello', false, "Original body.\n");

file_put_contents($rig . '/update-runner.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/bridge/core.php';
try {
    echo json_encode(Core\update('local'));
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]);
}
PHP);

$desc = [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
];
$proc = proc_open('php update-runner.php', $desc, $pipes, $rig);
$runnerOut = '';
if (is_resource($proc)) {
    $runnerOut = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
}
$answer = json_decode($runnerOut, true);
Harness::check('the local ride answers ok', is_array($answer) && ($answer['ok'] ?? false) === true,
    $runnerOut !== '' ? substr($runnerOut, 0, 300) : 'the runner was silent');

Harness::check('the floor moved to the packet version',
    trim((string)file_get_contents($rig . '/bridge/CORE-VERSION')) === $to,
    'CORE-VERSION reads ' . trim((string)file_get_contents($rig . '/bridge/CORE-VERSION')));
Harness::check('the domain ring SURVIVED the swap',
    is_file($rig . '/bridge/domain/error.php') && str_contains(
        (string)file_get_contents($rig . '/bridge/domain/error.php'), 'namespace Domain'),
    'the ring the update would have dropped');
Harness::check('the application ring SURVIVED the swap',
    is_file($rig . '/bridge/application/commands/page.php')
    && is_file($rig . '/bridge/application/queries/page.php'));
Harness::check('the smuggled plugin never landed', !is_file($rig . '/bridge/plugins/evil/plugin.php'));
Harness::check('the smuggled LOCAL config never landed (the admin account is still ours)',
    is_file($rig . '/bridge/config/accounts.local.php')
    && str_contains((string)file_get_contents($rig . '/bridge/config/accounts.local.php'), 'passwordHash'));
Harness::check('the content was never touched', str_contains(
    (string)file_get_contents($rig . '/src/content/pages/hello/index.md'), 'Original body.'));
Harness::check('the NEW pagetype shell landed (the merge law)',
    is_file($rig . '/pagetypes/extra.md'));
Harness::check('a local packet is consumed after the ride', !is_file($rig . '/bridge/cache/core-packet.zip'));
Harness::check('the blanko window closed behind the ride', !is_file($rig . '/bridge/cache/core-blank'));

// the rings still parse — a swapped core that cannot load is a brick
$lint = [];
foreach (['bridge/domain/error.php', 'bridge/domain/meta.php', 'bridge/application/commands/page.php'] as $rel) {
    exec('php -l ' . escapeshellarg($rig . '/' . $rel) . ' 2>&1', $lint);
}
Harness::check('the swapped rings still parse', !in_array(false, array_map(
    static fn (string $line): bool => str_contains($line, 'No syntax errors'), $lint), true),
    implode(' | ', $lint));

// the site still serves after the swap — the ultimate proof. The port is
// chosen FREE (a stray from an earlier killed run must not starve the proof)
// and the server is killed for keeps.
$port = 0;
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe) {
    // get_name answers "127.0.0.1:PORT" — (int) on that truncates at the
    // first dot (port 127, permission denied). Split on the colon.
    $port = (int)(explode(':', (string)stream_socket_get_name($probe, false))[1] ?? 0);
    stream_socket_shutdown($probe, STREAM_SHUT_RDWR); // a tiny race window — retried below
}
if ($port === 0) {
    $port = random_int(20000, 45000);
}
$serve = proc_open('php -S 127.0.0.1:' . $port . ' index.php', $desc, $pipes2, $rig);
usleep(800_000);
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
$home = $page = false;
for ($try = 0; $try < 5; $try++) {
    $home = @file_get_contents("http://127.0.0.1:{$port}/", false, $ctx);
    if (is_string($home) && $home !== '') {
        break;
    }
    usleep(400_000); // the server may still be binding
}
$page = @file_get_contents("http://127.0.0.1:{$port}/hello", false, $ctx);
Harness::check('the site still serves after the swap (home 200)', is_string($home) && $home !== '', gettype($home));
Harness::check('the content still answers after the swap (page 200)', is_string($page) && str_contains($page, 'Hello'),
    is_string($page) ? substr($page, 0, 120) : 'no body');
if (is_resource($serve)) {
    proc_terminate($serve);
    proc_close($serve);
    exec('pkill -f "127.0.0.1:' . $port . '" 2>/dev/null'); // no strays squat the next run
}

rigDestroy($rig);
@unlink($packet);
@rmdir($scratch);

Harness::verdict('core-update battery (the staging law + the full local ride)');

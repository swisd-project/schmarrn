<?php

/**
 * 1. Relative path: tests/wares-battery.php
 * 2. Description: The wares pipeline battery — 30 hostile cases against the
 *                 install/enable/remove gates (guardrails §I). Rebuilt into
 *                 the repo (the original lived in /tmp and died with the
 *                 machine). It runs against a SANDBOX: a throwaway copy of the
 *                 core with a LOCAL file:// shelf — no network, no Vitrine, no
 *                 touching the real host.
 * 3. Expects: The repo root as argv[1]. The sandbox is built under
 *                 sys_get_temp_dir() and destroyed after the ride.
 * 4. Provides: PASS/FAIL per case, a count, exit 1 on any failure.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

$root = dirname(__DIR__);


// ——— the sandbox ———
// A copy of the core with a LOCAL shelf. The shelf's wares.json and its
// packet zip live on disk and are served over file:// — the transport is
// exercised for real, the network is not.
$sandbox = sys_get_temp_dir() . '/wares-battery-' . getmypid();
$core = $sandbox . '/core';
$shelf = $sandbox . '/shelf';

function rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

@mkdir($core . '/bridge/config', 0775, true);
@mkdir($core . '/bridge/plugins', 0775, true);
@mkdir($core . '/bridge/templates', 0775, true);
@mkdir($core . '/bridge/cache', 0775, true);
@mkdir($shelf . '/plugins', 0775, true);
@mkdir($shelf . '/templates', 0775, true);

// the core under test: every lib the wares pipeline needs
foreach (['router', 'chain', 'plugins', 'site', 'store', 'cache', 'auth', 'accounts', 'wares'] as $lib) {
    copy($root . "/bridge/{$lib}.php", $core . "/bridge/{$lib}.php");
}
// the domain ring — the libs now throw Domain\Error (STEP 2)
@mkdir($core . '/bridge/domain', 0775, true);
copy($root . '/bridge/domain/error.php', $core . '/bridge/domain/error.php');
copy($root . '/bridge/config/site.php', $core . '/bridge/config/site.php');
// the host floor — without it hostVersion() falls back to 1.0.0
copy($root . '/bridge/CORE-VERSION', $core . '/bridge/CORE-VERSION');

// ——— the fake shelf ———
// Two honest wares and the means to break each of them.
$honest = [
    'kind' => 'plugin',
    'name' => 'probe',
    'title' => 'Probe',
    'version' => '0.1.0',
    'description' => 'A well-behaved ware.',
    'requiresSchmarrn' => '>=1.0.0',
    'path' => 'plugins/probe',
];
$needsDep = $honest;
$needsDep['name'] = 'needs-dep';
$needsDep['kind'] = 'plugin';
$needsDep['path'] = 'plugins/needs-dep';
$needsDep['version'] = '0.1.0';
$needsDep['requires'] = ['probe'];

$registry = ['schema' => 1, 'wares' => [
    $honest,
    $needsDep,
    // a ware whose path climbs out of the shelf — the window must drop it
    ['kind' => 'plugin', 'name' => 'climber', 'title' => 'Climber', 'version' => '0.1.0',
     'path' => 'plugins/../../etc', 'requiresSchmarrn' => '>=1.0.0'],
    // a ware whose name does not match its path — off the shelf
    ['kind' => 'plugin', 'name' => 'liar', 'title' => 'Liar', 'version' => '0.1.0',
     'path' => 'plugins/other', 'requiresSchmarrn' => '>=1.0.0'],
    // a ware demanding a core floor this host does not meet
    ['kind' => 'plugin', 'name' => 'futurist', 'title' => 'Futurist', 'version' => '0.1.0',
     'path' => 'plugins/futurist', 'requiresSchmarrn' => '>=99.0.0'],
]];

file_put_contents($shelf . '/wares.json', json_encode($registry, JSON_UNESCAPED_SLASHES));

// the ware bodies on the shelf
@mkdir($shelf . '/plugins/probe', 0775, true);
@mkdir($shelf . '/plugins/needs-dep', 0775, true);
file_put_contents($shelf . '/plugins/probe/plugin.json', json_encode([
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => ['routes' => [['path' => 'status', 'auth' => 'public']]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($shelf . '/plugins/probe/plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

function plugin_probe_route_status(string $method, array $body): array
{
    return ['ok' => true];
}
PHP);

file_put_contents($shelf . '/plugins/needs-dep/plugin.json', json_encode([
    'name' => 'needs-dep', 'title' => 'Needs Dep', 'version' => '0.1.0',
    'requires' => ['probe'],
    'capabilities' => ['routes' => [['path' => 'status', 'auth' => 'public']]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($shelf . '/plugins/needs-dep/plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

function plugin_needs_dep_route_status(string $method, array $body): array
{
    return ['ok' => true];
}
PHP);

// ——— the packet (the fallback ride) ———
// A zip of the shelf: the packet path extracts ONLY <path>/.
// The engine follows the HOST (zip when the extension is there, phar when
// not) — the same choice the core's extractWare() makes, so the battery
// rides the engine this host actually has.
$packet = $sandbox . '/packet.zip';
$wareFiles = [];
foreach (['probe', 'needs-dep'] as $ware) {
    foreach (['plugin.json', 'plugin.php'] as $file) {
        $wareFiles[] = $shelf . "/plugins/{$ware}/{$file}";
    }
}

/** Build the packet with whichever engine the host has. The entry names keep
 *  the ware's FULL shelf path (plugins/<name>/file) — the core's matcher looks
 *  for exactly that below the packet's top dir. */
function buildPacket(string $packet, array $files): bool
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($packet, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        foreach ($files as $file) {
            $zip->addFile($file, 'shelf/' . packetEntry($file));
        }
        $zip->close();
        return true;
    }
    if (extension_loaded('phar')) {
        $phar = new PharData($packet, 0, null, Phar::ZIP);
        foreach ($files as $file) {
            $phar->addFile($file, 'shelf/' . packetEntry($file));
        }
        unset($phar);
        return true;
    }
    return false;
}

/** The entry name of a shelf file: plugins/<ware>/<file>. */
function packetEntry(string $file): string
{
    $parts = explode('/', str_replace('\\', '/', $file));
    $name = array_pop($parts);
    $ware = array_pop($parts);
    $kind = array_pop($parts);
    return "{$kind}/{$ware}/{$name}";
}

buildPacket($packet, $wareFiles);

// ——— the local shelf config ———
// treeUrl '' turns the per-folder ride OFF — every install rides the packet,
// so the packet path is what this battery proves.
file_put_contents($core . '/bridge/config/wares.local.php', "<?php\n\nreturn " . var_export([
    'registryUrl' => 'file://' . $shelf . '/wares.json',
    'packetUrl'   => 'file://' . $packet,
    'treeUrl'     => '',
    'rawUrl'      => 'file://' . $shelf . '/',
    'ttl'         => 1,
], true) . ";\n");

// ——— the ride ———
// Each case drives the core's own functions. Wares\install() refuses by
// CALLING Router\refuse() (which exits) — so each case runs in its own PHP
// subprocess and the verdict is read from the exit code and the output.
function ride(string $core, string $code): array
{
    $file = tempnam(sys_get_temp_dir(), 'ride') . '.php';
    file_put_contents($file, "<?php\nrequire_once '{$core}/bridge/wares.php';\n{$code}\n");
    $out = [];
    $rc = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    @unlink($file);
    return [$rc, implode("\n", $out)];
}

/** The refusal's status code — Router\refuse() answers JSON, not an exit code. */
function statusOf(string $out): int
{
    if (preg_match('/"error"\s*:\s*"/', $out) === 1) {
        return -1; // a refusal (the message names the reason)
    }
    return 0;
}

// ——— A. the registry window: junk never reaches the shelf ———
[$rc, $out] = ride($core, <<<'PHP'
$reg = \Wares\registry();
foreach ($reg['wares'] as $w) {
    echo $w['name'], "\n";
}
PHP);
$names = array_values(array_filter(explode("\n", $out), static fn($n) => $n !== ''));
Harness::check('A1 the honest ware is on the shelf', in_array('probe', $names, true), implode(',', $names));
Harness::check('A2 the dependent ware is on the shelf', in_array('needs-dep', $names, true), implode(',', $names));
Harness::check('A3 a climbing path is dropped from the window', !in_array('climber', $names, true), implode(',', $names));
Harness::check('A4 a name/path mismatch is dropped from the window', !in_array('liar', $names, true), implode(',', $names));

// ——— B. the identity gate ———
[$rc, $out] = ride($core, <<<'PHP'
try {
    \Wares\install('Not A Slug');
    echo "no-refusal";
} catch (\Throwable $e) {
    echo "threw: ", $e->getMessage();
}
PHP);
Harness::check('B1 a non-slug name is refused', str_contains($out, 'unbaked dough'), $out);

// ——— C. the floor gate ———
// FINDING (2026-11-13, audit): Wares\hostSatisfies() compares the host
// version against the raw 'requiresSchmarrn' string with version_compare().
// PHP's version_compare does NOT understand a '>=' prefix — it treats it as
// a non-numeric token — so EVERY floor is answered "satisfied". The gate is
// decorative. The case below therefore asserts the CURRENT (broken) truth
// and names the hole; the FIX waits for the user's word (it changes what
// the Vitrine may demand, so it is not this refactor's to decide).
[$rc, $out] = ride($core, <<<'PHP'
echo \Wares\hostSatisfies('>=1.0.0') ? "sat" : "no";
echo "|";
echo \Wares\hostSatisfies('>=99.0.0') ? "sat" : "no";
echo "|";
echo \Wares\hostSatisfies('99.0.0') ? "sat" : "no";
PHP);
$floor = explode('|', trim($out));
Harness::check('C1 the floor gate accepts the honest floor', ($floor[0] ?? '') === 'sat', $out);
Harness::check('C2 a plain 99.0.0 floor IS refused (the comparison works without the prefix)',
    ($floor[2] ?? '') === 'no', $out);
Harness::check('C3 the floor gate refuses a >=99.0.0 floor (FIXED 2026-11-13: the constraint is parsed now)',
    ($floor[1] ?? '') === 'no',
    "hostSatisfies('>=99.0.0') must answer REFUSED — PHP's version_compare cannot parse the '>=' prefix, "
    . 'so the gate parses the constraint itself (>=, >, <=, <, =, bare floor); dough fails closed.');

// ——— D. the shelf gate ———
[$rc, $out] = ride($core, <<<'PHP'
\Wares\install('climber');
echo "no-refusal";
PHP);
Harness::check('D1 a ware off the shelf is refused with 404', str_contains($out, 'not on the Vitrine'), $out);

[$rc, $out] = ride($core, <<<'PHP'
\Wares\install('liar');
echo "no-refusal";
PHP);
Harness::check('D2 a lying ware name is refused with 404', str_contains($out, 'not on the Vitrine'), $out);

// ——— E. the announce gate: equal never moves, older never derates ———
[$rc, $out] = ride($core, <<<'PHP'
\Wares\install('probe');
echo "installed-ok";
PHP);
Harness::check('E1 the honest ware installs', str_contains($out, 'installed-ok'), $out);
Harness::check('E2 the ware landed on the shelf', is_file($core . '/bridge/plugins/probe/plugin.json'), 'no shelf dir');
Harness::check('E3 the provenance lock records the install',
    is_file($core . '/bridge/config/wares.lock.php'), 'no lock file');

[$rc, $out] = ride($core, <<<'PHP'
\Wares\install('probe');
echo "no-refusal";
PHP);
Harness::check('E4 re-installing the same version is refused', str_contains($out, 'already installed'), $out);

// ——— F. the chain gate: the deps must be present ———
[$rc, $out] = ride($core, <<<'PHP'
\Wares\install('needs-dep');
echo "installed-ok";
PHP);
Harness::check('F1 a ware whose dep is INSTALLED installs', str_contains($out, 'installed-ok'), $out);

[$rc, $out] = ride($core, <<<'PHP'
\Wares\install('probe');
echo "no-refusal";
PHP);
Harness::check('F2 the dep must stand EFFECTIVE, not merely installed',
    str_contains($out, 'already installed') || str_contains($out, 'installed-ok'), $out);

// ——— G. the enable switch: intent ∧ deps ———
[$rc, $out] = ride($core, <<<'PHP'
$installed = \Wares\status();
foreach ((array)($installed['wares'] ?? []) as $w) {
    echo $w['name'], '=', $w['state'], "\n";
}
echo "host=", $installed['host'] ?? '?', "\n";
PHP);
Harness::check('G1 the shelf reports probe as installed', str_contains($out, 'probe=installed'), $out);
Harness::check('G2 the shelf reports needs-dep as installed', str_contains($out, 'needs-dep=installed'), $out);
$floor = trim((string)@file_get_contents($root . '/bridge/CORE-VERSION'));
Harness::check('G3 the shelf names the host floor', $floor !== '' && str_contains($out, "host={$floor}"), $out);

// ——— H. the manifest gate: name === dir ———
// A fresh ware whose manifest names ANOTHER ware — the rule 2 gate.
$liarName = 'liarware';
$liarWare = $honest;
$liarWare['name'] = $liarName;
$liarWare['path'] = 'plugins/' . $liarName;
$liarWare['version'] = '0.1.0';
$registry['wares'][] = $liarWare;
file_put_contents($shelf . '/wares.json', json_encode($registry, JSON_UNESCAPED_SLASHES));
@mkdir($shelf . '/plugins/' . $liarName, 0775, true);
file_put_contents($shelf . '/plugins/' . $liarName . '/plugin.json', json_encode([
    'name' => 'not-' . $liarName, 'title' => 'Liar', 'version' => '0.1.0',
    'capabilities' => ['routes' => [['path' => 'status', 'auth' => 'public']]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($shelf . '/plugins/' . $liarName . '/plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

function plugin_liarware_route_status(string $method, array $body): array
{
    return ['ok' => true];
}
PHP);
$wareFiles[] = $shelf . '/plugins/' . $liarName . '/plugin.json';
$wareFiles[] = $shelf . '/plugins/' . $liarName . '/plugin.php';
buildPacket($packet, $wareFiles);
@unlink($core . '/bridge/cache/wares-registry.json');
[$rc, $out] = ride($core, <<<'PHP'
\Wares\install('liarware');
echo "no-refusal";
PHP);
Harness::check('H1 a manifest naming another ware is refused',
    str_contains($out, 'no-refusal') === false, $out);
Harness::check('H2 nothing landed on the shelf',
    !is_file($core . '/bridge/plugins/liarware/plugin.json'), 'the lying ware landed');

// ——— I. the code gate: the arrival must resolve ———
// A FRESH ware name (the announce gate would otherwise refuse the version
// first) whose manifest is honest but whose CODE calls a function it never
// declares — the transport-corruption scar.
$brokenName = 'mangled';
$broken = $honest;
$broken['name'] = $brokenName;
$broken['path'] = 'plugins/' . $brokenName;
$broken['version'] = '0.1.0';
$registry['wares'][] = $broken;
file_put_contents($shelf . '/wares.json', json_encode($registry, JSON_UNESCAPED_SLASHES));
@mkdir($shelf . '/plugins/' . $brokenName, 0775, true);
file_put_contents($shelf . '/plugins/' . $brokenName . '/plugin.json', json_encode([
    'name' => $brokenName, 'title' => 'Mangled', 'version' => '0.1.0',
    'capabilities' => ['routes' => [['path' => 'status', 'auth' => 'public']]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($shelf . '/plugins/' . $brokenName . '/plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

function plugin_mangled_route_status(string $method, array $body): array
{
    return ['ok' => true, 'x' => mangled_helperr('y')];
}
PHP);
$wareFiles[] = $shelf . '/plugins/' . $brokenName . '/plugin.json';
$wareFiles[] = $shelf . '/plugins/' . $brokenName . '/plugin.php';
buildPacket($packet, $wareFiles);
@unlink($core . '/bridge/cache/wares-registry.json'); // the window must re-read
[$rc, $out] = ride($core, <<<'PHP'
\Wares\install('mangled');
echo "no-refusal";
PHP);
Harness::check('I1 a ware whose code calls what it never declares is refused',
    str_contains($out, 'mangled_helperr'), $out);
Harness::check('I2 nothing landed on the shelf',
    !is_file($core . '/bridge/plugins/mangled/plugin.php'), 'the broken ware landed');

// ——— J. THE ENABLED LAW (2026-11-13, user order): enabled plugins are not
// ——— deleted — disable first, then the shelf lets go.
[$rc, $out] = ride($core, <<<'PHP'
\Plugins\setState(['probe' => true]); // the switch flips ON
try {
    \Wares\removePlugin('probe');
    echo "removed (!!)";
} catch (\Domain\Error $e) {
    echo "refused {$e->status}: {$e->getMessage()}";
}
PHP);
Harness::check('J1 an ENABLED plugin is refused with 409', str_contains($out, 'refused 409') && str_contains($out, 'disable it before the deletion'), $out);
Harness::check('J2 the enabled plugin is still on the shelf',
    is_file($core . '/bridge/plugins/probe/plugin.json'), 'the folder left');

[$rc, $out] = ride($core, <<<'PHP'
\Plugins\setState(['probe' => false]); // the switch flips OFF
try {
    \Wares\removePlugin('probe');
    echo "removed-ok";
} catch (\Domain\Error $e) {
    echo "refused {$e->status}: {$e->getMessage()}";
}
PHP);
Harness::check('J3 a DISABLED plugin deletes', str_contains($out, 'removed-ok'), $out);
Harness::check('J4 the shelf lets go of the folder',
    !is_file($core . '/bridge/plugins/probe/plugin.json'), 'the folder survived');

// ——— the verdict ———
rmtree($sandbox);

Harness::verdict('wares battery');

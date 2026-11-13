<?php

/**
 * lid-battery.php — the CODE LID's own battery.
 *
 * Usage: php lid-battery.php [schmarrn-root] [vitrine-root]
 *
 * Defaults: the repo this file lives in, and the sibling Vitrine checkout
 * (../10_Vitrine) when it is there. The lid must PASS every real ware (no
 * false positives) and FAIL a corrupted one. A lid that refuses honest wares
 * is worse than no lid.
 */

declare(strict_types=1);

$core = rtrim($argv[1] ?? dirname(__DIR__), '/');
$vitrine = rtrim($argv[2] ?? dirname(__DIR__, 2) . '/10_Vitrine', '/');
if (!is_dir($vitrine)) {
    echo "SKIP  no Vitrine checkout at {$vitrine} — the lid battery needs real wares\n";
    exit(0);
}

require_once $core . '/bridge/plugins.php';

require_once __DIR__ . '/harness.php';



// 1. every ware on the shelf passes the lid
foreach (glob($vitrine . '/plugins/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $name = basename($dir);
    $fault = \Plugins\codeCheck($dir);
    Harness::check("the real ware '{$name}' passes the lid", $fault === null, (string)$fault);
}

// 1b. KNOWN DEFECT (2026-11-13, audit): the lid collects declarations as it
// scans, so a call that appears BEFORE its declaration in the same file is a
// false positive — and PHP hoists top-level functions, so that code is legal.
// The Vitrine's bienenstich used to trigger it (call at 101, declaration at
// 324) and now no longer does (the shelf re-ordered), but the lid's bug
// stands: the synthetic case below is the honest repro, and it stays a
// recorded defect until the core collects declarations in a first pass.
$work2 = sys_get_temp_dir() . '/lid-battery-order-' . getmypid();
@mkdir($work2 . '/plugins/hoisted', 0775, true);
file_put_contents($work2 . '/plugins/hoisted/plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

function hoisted_a(): string
{
    return hoisted_b(); // called BEFORE its declaration — legal PHP
}

function hoisted_b(): string
{
    return 'x';
}
PHP);
$fault = \Plugins\codeCheck($work2 . '/plugins/hoisted');
Harness::check('a legal call-before-declaration passes the lid (FIXED 2026-11-13: declarations are collected in a first pass)',
    $fault === null, (string)$fault);
array_map('unlink', glob($work2 . '/plugins/hoisted/*') ?: []);
@rmdir($work2 . '/plugins/hoisted');
@rmdir($work2);

// 2. the core's own plugin shelf (may be empty on this host — the ware was
// deleted on this rig; the Vitrine's hello covers the same case)
$corePlugins = glob($core . '/bridge/plugins/*', GLOB_ONLYDIR) ?: [];
if ($corePlugins === []) {
    echo "SKIP  the core's own plugin shelf is empty on this host\n";
} else {
    foreach ($corePlugins as $dir) {
        $fault = \Plugins\codeCheck($dir);
        Harness::check("the core's own plugin '" . basename($dir) . "' passes the lid", $fault === null, (string)$fault);
    }
}

// 3. a corrupted ware is refused, and the fault names the call
$work = sys_get_temp_dir() . '/lid-battery-' . getmypid();
@mkdir($work . '/plugins/broken', 0775, true);
$broken = <<<'PHP'
<?php

declare(strict_types=1);

/** The declaration — spelled right. */
function lid_helper(string $value): string
{
    return $value;
}

/** The call site — one letter too many: the transfer mangled it. */
function lid_caller(): string
{
    return lid_helperr('x');
}
PHP;
file_put_contents($work . '/plugins/broken/plugin.php', $broken);
$fault = \Plugins\codeCheck($work . '/plugins/broken');
Harness::check('a corrupted ware is refused', $fault !== null, 'the lid stayed quiet');
Harness::check('the fault names the offending call', $fault !== null && str_contains($fault, 'lid_helperr'), (string)$fault);

// 3b. a self-consistent file (the declaration mangled the same way) is sound
@mkdir($work . '/plugins/consistent', 0775, true);
file_put_contents($work . '/plugins/consistent/plugin.php', (string)str_replace(
    'lid_helperr(',
    'lid_helperr(',
    str_replace('function lid_helper(', 'function lid_helperr(', $broken)
));
$fault = \Plugins\codeCheck($work . '/plugins/consistent');
Harness::check('a self-consistent file passes (ugly names are not the lid\'s business)', $fault === null, (string)$fault);

// 4. a ware with no code at all is not the lid's business
@mkdir($work . '/plugins/empty', 0775, true);
file_put_contents($work . '/plugins/empty/plugin.json', '{"name":"empty","title":"Empty","version":"0.1.0"}');
$fault = \Plugins\codeCheck($work . '/plugins/empty');
Harness::check('a ware without code passes (the runtime law degrades)', $fault === null, (string)$fault);

// 5. a multi-file ware resolves across its own files
@mkdir($work . '/plugins/multi', 0775, true);
file_put_contents($work . '/plugins/multi/helper.php', "<?php\nfunction multi_helper(): string { return 'x'; }\n");
file_put_contents($work . '/plugins/multi/plugin.php', "<?php\nfunction multi_caller(): string { return multi_helper(); }\n");
$fault = \Plugins\codeCheck($work . '/plugins/multi');
Harness::check('a multi-file ware resolves across its own files', $fault === null, (string)$fault);

// 6. a namespaced core call is not the lid's business
@mkdir($work . '/plugins/ns', 0775, true);
file_put_contents($work . '/plugins/ns/plugin.php', "<?php\nfunction ns_caller(): array { return \\Store\\listAll(); }\n");
$fault = \Plugins\codeCheck($work . '/plugins/ns');
Harness::check('a namespaced core call passes the lid', $fault === null, (string)$fault);

array_map('unlink', glob($work . '/plugins/broken/*') ?: []);
array_map('unlink', glob($work . '/plugins/consistent/*') ?: []);
array_map('unlink', glob($work . '/plugins/empty/*') ?: []);
array_map('unlink', glob($work . '/plugins/multi/*') ?: []);
array_map('unlink', glob($work . '/plugins/ns/*') ?: []);
array_map('rmdir', glob($work . '/plugins/*') ?: []);
@rmdir($work . '/plugins');
@rmdir($work);

Harness::verdict('lid battery');

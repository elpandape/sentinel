<?php

declare(strict_types=1);

it('compares two structures in a process that never boots an application', function (): void {
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';

    $source = <<<PHP
    <?php
    require '{$autoload}';
    \$diff = ElPandaPe\\Sentinel\\Diff\\Diff::between(['a' => 1, 'b' => [1, 2]], ['a' => 2, 'b' => [1, 2]]);
    echo json_encode(\$diff->toArray());
    echo class_exists('Illuminate\\\\Container\\\\Container', false) ? '|container' : '|clean';
    PHP;

    $script = tempnam(sys_get_temp_dir(), 'sentinel-diff-');

    file_put_contents($script, $source);

    $output = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script));

    unlink($script);

    expect($output)->toBe('[{"path":"\/a","op":"replace","old":1,"new":2}]|clean');
});

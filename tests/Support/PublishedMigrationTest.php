<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\PublishedMigration;

it('finds no copy in an empty directory', function (): void {
    $directory = sys_get_temp_dir().'/sentinel-'.uniqid();
    mkdir($directory);

    expect(new PublishedMigration($directory, 'create_sentinel_audits_table')->exists())->toBeFalse();

    rmdir($directory);
});

it('finds a copy whatever timestamp it carries', function (): void {
    $directory = sys_get_temp_dir().'/sentinel-'.uniqid();
    mkdir($directory);
    touch($directory.'/2030_01_01_000000_create_sentinel_audits_table.php');

    expect(new PublishedMigration($directory, 'create_sentinel_audits_table')->exists())->toBeTrue();

    unlink($directory.'/2030_01_01_000000_create_sentinel_audits_table.php');
    rmdir($directory);
});

it('ignores a directory that does not exist', function (): void {
    expect(new PublishedMigration('/nonexistent/sentinel', 'create_sentinel_audits_table')->exists())
        ->toBeFalse();
});

it('ignores a migration with another name', function (): void {
    $directory = sys_get_temp_dir().'/sentinel-'.uniqid();
    mkdir($directory);
    touch($directory.'/2030_01_01_000000_create_users_table.php');

    expect(new PublishedMigration($directory, 'create_sentinel_audits_table')->exists())->toBeFalse();

    unlink($directory.'/2030_01_01_000000_create_users_table.php');
    rmdir($directory);
});

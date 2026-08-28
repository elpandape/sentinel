<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\PackageMigrations;

use function ElPandaPe\Sentinel\Tests\discardMigrationDirectories;
use function ElPandaPe\Sentinel\Tests\migrationDirectories;

it('offers every migration when the application published none', function (): void {
    [$root, $package, $published] = migrationDirectories(
        package: ['2026_08_26_000000_create_sentinel_audits_table.php', '2026_08_28_000000_create_sentinel_audit_tags_table.php'],
    );

    expect(new PackageMigrations($package, $published)->unpublished())->toHaveCount(2);

    discardMigrationDirectories($root);
});

it('withholds only the migration the application published', function (): void {
    [$root, $package, $published] = migrationDirectories(
        package: ['2026_08_26_000000_create_sentinel_audits_table.php', '2026_08_28_000000_create_sentinel_audit_tags_table.php'],
        published: ['2030_01_01_000000_create_sentinel_audits_table.php'],
    );

    expect(new PackageMigrations($package, $published)->unpublished())
        ->toBe([$package.'/2026_08_28_000000_create_sentinel_audit_tags_table.php']);

    discardMigrationDirectories($root);
});

it('withholds every migration the application took over', function (): void {
    [$root, $package, $published] = migrationDirectories(
        package: ['2026_08_26_000000_create_sentinel_audits_table.php', '2026_08_28_000000_create_sentinel_audit_tags_table.php'],
        published: ['2030_01_01_000000_create_sentinel_audits_table.php', '2030_01_02_000000_create_sentinel_audit_tags_table.php'],
    );

    expect(new PackageMigrations($package, $published)->unpublished())->toBeEmpty();

    discardMigrationDirectories($root);
});

it('offers nothing from a directory that does not exist', function (): void {
    expect(new PackageMigrations('/nonexistent/sentinel', sys_get_temp_dir())->unpublished())->toBeEmpty();
});

it('reads the name of a migration that carries no timestamp', function (): void {
    [$root, $package, $published] = migrationDirectories(
        package: ['create_sentinel_audits_table.php'],
        published: ['2030_01_01_000000_create_sentinel_audits_table.php'],
    );

    expect(new PackageMigrations($package, $published)->unpublished())->toBeEmpty();

    discardMigrationDirectories($root);
});

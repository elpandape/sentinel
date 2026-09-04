<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\InstallCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Sentinel\Tests\sentinelConfig;

beforeEach(function (): void {
    $this->configuration = sys_get_temp_dir().'/sentinel-install-'.getmypid();

    File::deleteDirectory($this->configuration);
    app()->useConfigPath($this->configuration);
});

afterEach(function (): void {
    File::deleteDirectory($this->configuration);
});

it('publishes the configuration an application has not got yet', function (): void {
    $this->artisan('sentinel:install')
        ->expectsOutputToContain('Published the configuration')
        ->assertSuccessful();

    expect(File::exists($this->configuration.'/sentinel.php'))->toBeTrue()
        ->and(require $this->configuration.'/sentinel.php')->toHaveKey('integrity');
});

it('leaves an edited configuration exactly as it found it', function (): void {
    File::ensureDirectoryExists($this->configuration);
    File::put($this->configuration.'/sentinel.php', "<?php return ['mine' => true];");

    $this->artisan('sentinel:install')
        ->expectsOutputToContain('already there, and nothing in it was touched')
        ->assertSuccessful();

    expect(File::get($this->configuration.'/sentinel.php'))->toBe("<?php return ['mine' => true];");
});

it('reports every table as present once the migrations have run', function (): void {
    $this->artisan('sentinel:install')
        ->expectsOutputToContain('tables are present')
        ->assertSuccessful();
});

it('names the tables that are not there yet and points at migrate', function (): void {
    Schema::drop(sentinelConfig()->table('archives'));

    $this->artisan('sentinel:install')
        ->expectsOutputToContain('1 of 7 tables are not there yet: sentinel_archives')
        ->expectsOutputToContain('Run php artisan migrate')
        ->assertSuccessful();
});

it('names what is publishable and left unpublished, so nothing is discovered by accident', function (): void {
    $this->artisan('sentinel:install')
        ->expectsOutputToContain('sentinel-partitioned-pgsql-tenant')
        ->assertSuccessful();
});

it('cannot check a schema it cannot reach, and says so instead of exiting sound', function (): void {
    config()->set('sentinel.database.connection', 'nowhere');

    $this->artisan('sentinel:install')
        ->expectsOutputToContain('the schema could not be read')
        ->assertExitCode(2);
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(InstallCommand::class)->getDescription())
        ->toBe('Publica la configuración e informa de lo que le falta a una instalación');
});

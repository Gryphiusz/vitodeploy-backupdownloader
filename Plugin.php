<?php

namespace App\Vito\Plugins\Arnobolt\BackupDownloader;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterServerFeature;
use App\Plugins\RegisterServerFeatureAction;
use App\Vito\Plugins\Arnobolt\BackupDownloader\Actions\GenerateBackupDownloadLink;
use App\Vito\Plugins\Arnobolt\BackupDownloader\Http\Controllers\DownloadBackupController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class Plugin extends AbstractPlugin
{
    protected string $name = 'Backup Downloader';

    protected string $description = 'Generate secure links to download server backup files.';

    public function boot(): void
    {
        RegisterServerFeature::make('backup-downloader')
            ->label('Backup Downloader')
            ->description('Generate temporary links to download backup files from this server.')
            ->register();

        RegisterServerFeatureAction::make('backup-downloader', 'generate-link')
            ->label('Generate Link')
            ->handler(GenerateBackupDownloadLink::class)
            ->register();

        Route::middleware(['web', 'auth', 'has-project'])
            ->get('/plugins/backup-downloader/download/{token}', DownloadBackupController::class)
            ->name('plugins.backup-downloader.download');
    }

    public function install(): void
    {
        $this->runMigrations();
    }

    public function enable(): void
    {
        $this->runMigrations();
    }

    public function uninstall(): void
    {
        try {
            Artisan::call('migrate:rollback', [
                '--path' => $this->migrationPath(),
                '--realpath' => true,
                '--force' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Backup Downloader plugin uninstall rollback failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            Schema::dropIfExists('backup_downloader_links');
        } catch (\Throwable $exception) {
            Log::warning('Backup Downloader plugin uninstall table cleanup failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function runMigrations(): void
    {
        try {
            Artisan::call('migrate', [
                '--path' => $this->migrationPath(),
                '--realpath' => true,
                '--force' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Backup Downloader plugin migration failed', [
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function migrationPath(): string
    {
        return __DIR__.'/Database/migrations';
    }
}

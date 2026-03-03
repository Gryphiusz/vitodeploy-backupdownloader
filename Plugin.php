<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader;

use App\Plugins\AbstractPlugin;
use App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Http\Controllers\DownloadBackupController;
use App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Http\Controllers\DownloadBackupFromMenuController;
use App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Services\BackupFilesMenuPatchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class Plugin extends AbstractPlugin
{
    protected string $name = 'Backup Downloader';

    protected string $description = 'Adds a Download action to backup file menus and serves secure one-time downloads.';

    private static bool $menuPatchChecked = false;

    public function boot(): void
    {
        $this->applyBackupMenuPatchOnce();

        Route::middleware(['web', 'auth', 'has-project'])
            ->get('/plugins/backup-downloader/download/{token}', DownloadBackupController::class)
            ->name('plugins.backup-downloader.download');

        Route::middleware(['web', 'auth', 'has-project'])
            ->get(
                '/plugins/backup-downloader/servers/{server}/backups/{backup}/files/{backupFile}/download',
                DownloadBackupFromMenuController::class
            )
            ->name('plugins.backup-downloader.direct-download');
    }

    public function install(): void
    {
        $this->runMigrations();
        $this->applyBackupMenuPatchOnce();
    }

    public function enable(): void
    {
        $this->runMigrations();
        $this->applyBackupMenuPatchOnce();
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

    private function applyBackupMenuPatchOnce(): void
    {
        if (self::$menuPatchChecked) {
            return;
        }

        self::$menuPatchChecked = true;

        try {
            app(BackupFilesMenuPatchService::class)->apply();
        } catch (\Throwable $exception) {
            Log::warning('Backup Downloader plugin could not apply backups menu patch', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

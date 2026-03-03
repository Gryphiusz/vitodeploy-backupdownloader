<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\BackupFile;
use App\Models\Server;
use App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Services\BackupDownloadService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadBackupFromMenuController extends Controller
{
    public function __invoke(
        Server $server,
        Backup $backup,
        BackupFile $backupFile,
        BackupDownloadService $downloadService
    ): BinaryFileResponse
    {
        $this->authorize('view', $server);
        $this->authorize('view', $backupFile);

        if ((int) $backup->server_id !== (int) $server->id) {
            abort(404);
        }

        if ((int) $backupFile->backup_id !== (int) $backup->id) {
            abort(404);
        }

        if (! $backupFile->isAvailable()) {
            abort(422, 'Backup file is not currently available for download.');
        }

        return $downloadService->download($backupFile);
    }
}

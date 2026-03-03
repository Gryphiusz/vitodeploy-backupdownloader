<?php

namespace App\Vito\Plugins\Arnobolt\BackupDownloader\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Vito\Plugins\Arnobolt\BackupDownloader\Models\BackupDownloadLink;
use App\Vito\Plugins\Arnobolt\BackupDownloader\Services\BackupDownloadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadBackupController extends Controller
{
    public function __invoke(Request $request, string $token, BackupDownloadService $downloadService): BinaryFileResponse
    {
        $link = BackupDownloadLink::query()
            ->where('token', $token)
            ->with(['backupFile.backup.storage', 'backupFile.backup.server'])
            ->firstOrFail();

        $userId = $request->user()?->id;
        if ($userId === null || (int) $link->user_id !== (int) $userId) {
            abort(403, 'This download link does not belong to your account.');
        }

        if ($link->isUsed()) {
            abort(410, 'This download link has already been used.');
        }

        if ($link->isExpired()) {
            abort(410, 'This download link has expired.');
        }

        $backupFile = $link->backupFile;
        if (! $backupFile || (int) $backupFile->backup->server_id !== (int) $link->server_id) {
            abort(404, 'Backup file not found for this server.');
        }

        if (! $backupFile->isAvailable()) {
            abort(422, 'Backup file is not currently available for download.');
        }

        $this->authorize('view', $backupFile);

        $response = $downloadService->download($backupFile);

        $link->used_at = now();
        $link->save();

        return $response;
    }
}

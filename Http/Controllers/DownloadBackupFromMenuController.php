<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\BackupFile;
use App\Models\Server;
use App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Models\BackupDownloadLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DownloadBackupFromMenuController extends Controller
{
    public function __invoke(Server $server, Backup $backup, BackupFile $backupFile): RedirectResponse
    {
        $this->authorize('view', [$server]);
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

        $userId = Auth::id();
        if ($userId === null) {
            abort(403, 'You must be authenticated to download backups.');
        }

        $minutes = max(1, (int) config('vito.plugins.backup_downloader.link_expiration_minutes', 15));

        $link = BackupDownloadLink::query()->create([
            'token' => Str::random(64),
            'server_id' => $server->id,
            'user_id' => $userId,
            'backup_file_id' => $backupFile->id,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return redirect()->to(route('plugins.backup-downloader.download', [
            'token' => $link->token,
        ]));
    }
}

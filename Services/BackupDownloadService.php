<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Services;

use App\Models\BackupFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupDownloadService
{
    /**
     * @throws Throwable
     */
    public function download(BackupFile $backupFile): BinaryFileResponse
    {
        $server = $backupFile->backup->server;
        $sourcePath = $backupFile->path();

        $downloadName = basename($sourcePath);
        $tempName = 'backup-download-'.$backupFile->id.'-'.Str::uuid()->toString();
        $serverTempPath = '/tmp/'.$tempName;
        $localTempPath = Storage::disk('tmp')->path($tempName);

        try {
            // Step 1: fetch from configured storage provider to a temporary path on the managed server.
            $backupFile->backup->storage->provider()->ssh($server)->download($sourcePath, $serverTempPath);

            // Step 2: pull that temporary file from the managed server to Vito's local tmp storage.
            $server->ssh()->download($localTempPath, $serverTempPath);
        } catch (Throwable $exception) {
            if (file_exists($localTempPath)) {
                @unlink($localTempPath);
            }

            throw $exception;
        } finally {
            try {
                $server->os()->deleteFile($serverTempPath);
            } catch (Throwable $cleanupException) {
                Log::warning('Backup Downloader plugin failed to cleanup server temp file', [
                    'server_id' => $server->id,
                    'path' => $serverTempPath,
                    'error' => $cleanupException->getMessage(),
                ]);
            }
        }

        return response()->download($localTempPath, $downloadName)->deleteFileAfterSend(true);
    }
}

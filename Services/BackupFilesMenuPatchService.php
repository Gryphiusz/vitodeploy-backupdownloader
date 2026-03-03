<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Services;

use Illuminate\Support\Facades\Log;

class BackupFilesMenuPatchService
{
    private const TARGET_FILE = 'resources/js/pages/backups/components/file-columns.tsx';
    private const ROUTE_MARKER = "plugins.backup-downloader.direct-download";
    private const INSERT_AFTER = "<DropdownMenuItem onSelect={(e) => e.preventDefault()}>Restore</DropdownMenuItem>\n              </RestoreBackup>";
    private const DELETE_LINE = '              <Delete file={row.original} />';

    public function apply(): void
    {
        $targetPath = base_path(self::TARGET_FILE);
        if (! is_file($targetPath)) {
            return;
        }

        $content = file_get_contents($targetPath);
        if (! is_string($content) || $content === '') {
            return;
        }

        if (str_contains($content, self::ROUTE_MARKER)) {
            return;
        }

        $downloadMenuItem = <<<'TSX'
              <DropdownMenuItem asChild>
                <a
                  href={route('plugins.backup-downloader.direct-download', {
                    server: row.original.server_id,
                    backup: row.original.backup_id,
                    backupFile: row.original.id,
                  })}
                >
                  Download
                </a>
              </DropdownMenuItem>
TSX;

        $updated = false;

        if (str_contains($content, self::INSERT_AFTER)) {
            $content = str_replace(
                self::INSERT_AFTER,
                self::INSERT_AFTER."\n".$downloadMenuItem,
                $content
            );
            $updated = true;
        } elseif (str_contains($content, self::DELETE_LINE)) {
            $content = str_replace(
                self::DELETE_LINE,
                $downloadMenuItem."\n".self::DELETE_LINE,
                $content
            );
            $updated = true;
        }

        if (! $updated) {
            Log::warning('Backup Downloader plugin could not locate backup file menu anchors to patch.', [
                'target' => self::TARGET_FILE,
            ]);

            return;
        }

        file_put_contents($targetPath, $content);

        Log::info('Backup Downloader plugin patched backups file menu with Download action.', [
            'target' => self::TARGET_FILE,
            'note' => 'If frontend assets are prebuilt, run npm run build in the Vito root to apply UI changes.',
        ]);
    }
}

<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Services;

use Illuminate\Support\Facades\Log;

class BackupFilesMenuPatchService
{
    private const SOURCE_TARGET_FILE = 'resources/js/pages/backups/components/file-columns.tsx';
    private const BUILD_MANIFEST_FILE = 'public/build/manifest.json';
    private const ROUTE_MARKER = "plugins.backup-downloader.direct-download";
    private const INSERT_AFTER = "<DropdownMenuItem onSelect={(e) => e.preventDefault()}>Restore</DropdownMenuItem>\n              </RestoreBackup>";
    private const DELETE_LINE = '              <Delete file={row.original} />';

    public function apply(): void
    {
        $patchedSource = $this->patchSourceFile();
        $patchedBuilt = $this->patchBuiltFile();

        if ($patchedSource || $patchedBuilt) {
            Log::info('Backup Downloader plugin patched backups file menu with Download action.', [
                'source_target' => self::SOURCE_TARGET_FILE,
                'build_manifest' => self::BUILD_MANIFEST_FILE,
            ]);
            return;
        }

        Log::warning('Backup Downloader plugin could not patch backups file menu.', [
            'source_target' => self::SOURCE_TARGET_FILE,
            'build_manifest' => self::BUILD_MANIFEST_FILE,
            'note' => 'If this is a custom Vito build, menu component paths may have changed.',
        ]);
    }

    private function patchSourceFile(): bool
    {
        $targetPath = base_path(self::SOURCE_TARGET_FILE);
        if (! is_file($targetPath)) {
            return false;
        }

        $content = file_get_contents($targetPath);
        if (! is_string($content) || $content === '') {
            return false;
        }

        if (str_contains($content, self::ROUTE_MARKER)) {
            return true;
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
            return false;
        }

        return $this->writeFile($targetPath, $content);
    }

    private function patchBuiltFile(): bool
    {
        $manifestPath = base_path(self::BUILD_MANIFEST_FILE);
        $candidateChunks = [];

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest)) {
                $entry = $manifest['resources/js/pages/backups/components/file-columns.tsx']['file'] ?? null;
                if (is_string($entry) && $entry !== '') {
                    $candidateChunks[] = base_path('public/build/'.$entry);
                }
            }
        }

        foreach (glob((string) base_path('public/build/assets/*.js')) as $assetChunk) {
            if (is_string($assetChunk) && $assetChunk !== '') {
                $candidateChunks[] = $assetChunk;
            }
        }

        $candidateChunks = array_values(array_unique(array_filter($candidateChunks)));
        if ($candidateChunks === []) {
            return false;
        }

        foreach ($candidateChunks as $chunkPath) {
            if (! is_file($chunkPath)) {
                continue;
            }

            $content = file_get_contents($chunkPath);
            if (! is_string($content) || $content === '') {
                continue;
            }

            if (! str_contains($content, 'backup-files.destroy') || ! str_contains($content, 'children:"Restore"')) {
                continue;
            }

            if (str_contains($content, self::ROUTE_MARKER)) {
                return true;
            }

            $itemAliasMatch = [];
            $itemAliasPattern = '/import\{[^}]*c as (?<alias>[A-Za-z_\$][A-Za-z0-9_\$]*)\}from"\.\/dropdown-menu-[^"]+\.js";/';
            if (preg_match($itemAliasPattern, $content, $itemAliasMatch) !== 1) {
                continue;
            }
            $menuItemAlias = $itemAliasMatch['alias'];

            $deleteCallMatch = [];
            $deleteCallPattern = '/,e\.jsx\((?<delete>[A-Za-z_\$][A-Za-z0-9_\$]*),\{file:(?<row>[A-Za-z_\$][A-Za-z0-9_\$]*)\.original\}\)/';
            if (preg_match($deleteCallPattern, $content, $deleteCallMatch) !== 1) {
                continue;
            }
            $deleteCall = $deleteCallMatch[0];
            $rowAlias = $deleteCallMatch['row'];

            $downloadCall = ',e.jsx('.$menuItemAlias.',{asChild:!0,children:e.jsx("a",{href:route("plugins.backup-downloader.direct-download",{server:'.$rowAlias.'.original.server_id,backup:'.$rowAlias.'.original.backup_id,backupFile:'.$rowAlias.'.original.id}),children:"Download"})})';
            $updatedContent = preg_replace('/'.preg_quote($deleteCall, '/').'/', $downloadCall.$deleteCall, $content, 1, $count);

            if (! is_string($updatedContent) || $count < 1) {
                continue;
            }

            return $this->writeFile($chunkPath, $updatedContent);
        }

        return false;
    }

    private function writeFile(string $path, string $content): bool
    {
        $bytes = @file_put_contents($path, $content);
        if ($bytes === false) {
            Log::warning('Backup Downloader plugin failed writing patched file.', [
                'path' => $path,
            ]);
            return false;
        }

        return true;
    }
}

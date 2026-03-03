<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Services;

use Illuminate\Support\Facades\Log;

class BackupFilesMenuPatchService
{
    private const SOURCE_TARGET_FILE = 'resources/js/pages/backups/components/file-columns.tsx';
    private const BUILD_MANIFEST_FILE = 'public/build/manifest.json';
    private const LEGACY_ROUTE_MARKER = "plugins.backup-downloader.direct-download";
    private const SOURCE_URL_MARKER = '/plugins/backup-downloader/servers/${row.original.server_id}/backups/${row.original.backup_id}/files/${row.original.id}/download';
    private const BUILD_URL_MARKER = '/plugins/backup-downloader/servers/"+';
    private const INSERT_AFTER = "<DropdownMenuItem onSelect={(e) => e.preventDefault()}>Restore</DropdownMenuItem>\n              </RestoreBackup>";
    private const DELETE_LINE = '              <Delete file={row.original} />';

    public function apply(): void
    {
        $patchedSource = $this->patchSourceFile();
        $patchedBuilt = $this->patchBuiltFiles();

        if ($patchedSource || $patchedBuilt) {
            Log::info('Backup Downloader plugin patched backups file menu with Download action.', [
                'source_target' => self::SOURCE_TARGET_FILE,
                'build_manifest' => self::BUILD_MANIFEST_FILE,
            ]);
        }
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

        if (str_contains($content, self::SOURCE_URL_MARKER)) {
            return false;
        }

        if (str_contains($content, self::LEGACY_ROUTE_MARKER)) {
            $updatedContent = preg_replace(
                "/href=\\{route\\('plugins\\.backup-downloader\\.direct-download',\\s*\\{\\s*server:\\s*row\\.original\\.server_id,\\s*backup:\\s*row\\.original\\.backup_id,\\s*backupFile:\\s*row\\.original\\.id,\\s*\\}\\)\\}/m",
                'href={`/plugins/backup-downloader/servers/${row.original.server_id}/backups/${row.original.backup_id}/files/${row.original.id}/download`}',
                $content,
                1,
                $count
            );

            if (is_string($updatedContent) && $count > 0) {
                return $this->writeFile($targetPath, $updatedContent);
            }
        }

        $downloadMenuItem = <<<'TSX'
              <DropdownMenuItem asChild>
                <a
                  href={`/plugins/backup-downloader/servers/${row.original.server_id}/backups/${row.original.backup_id}/files/${row.original.id}/download`}
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

    private function patchBuiltFiles(): bool
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

        $patchedAny = false;

        foreach ($candidateChunks as $chunkPath) {
            if ($this->patchBuiltChunk((string) $chunkPath)) {
                $patchedAny = true;
            }
        }

        return $patchedAny;
    }

    private function patchBuiltChunk(string $chunkPath): bool
    {
        if (! is_file($chunkPath)) {
            return false;
        }

        $content = file_get_contents($chunkPath);
        if (! is_string($content) || $content === '') {
            return false;
        }

        if (! str_contains($content, 'backup-files.destroy') || ! str_contains($content, 'children:"Restore"')) {
            return false;
        }

        if (str_contains($content, self::BUILD_URL_MARKER)) {
            return false;
        }

        if (str_contains($content, self::LEGACY_ROUTE_MARKER)) {
            $updatedLegacy = preg_replace_callback(
                '/route\("plugins\.backup-downloader\.direct-download",\{server:(?<row>[A-Za-z_\$][A-Za-z0-9_\$]*)\.original\.server_id,backup:\k<row>\.original\.backup_id,backupFile:\k<row>\.original\.id\}\)/',
                static fn (array $matches): string => '"/plugins/backup-downloader/servers/"+'.$matches['row'].'.original.server_id+"/backups/"+'.$matches['row'].'.original.backup_id+"/files/"+'.$matches['row'].'.original.id+"/download"',
                $content,
                1,
                $count
            );

            if (is_string($updatedLegacy) && $count > 0) {
                return $this->writeFile($chunkPath, $updatedLegacy);
            }
        }

        $itemAliasMatch = [];
        $itemAliasPattern = '/import\{[^}]*c as (?<alias>[A-Za-z_\$][A-Za-z0-9_\$]*)\}from"\.\/dropdown-menu-[^"]+\.js";/';
        if (preg_match($itemAliasPattern, $content, $itemAliasMatch) !== 1) {
            return false;
        }
        $menuItemAlias = $itemAliasMatch['alias'];

        $deleteCallMatch = [];
        $deleteCallPattern = '/,e\.jsx\((?<delete>[A-Za-z_\$][A-Za-z0-9_\$]*),\{file:(?<row>[A-Za-z_\$][A-Za-z0-9_\$]*)\.original\}\)/';
        if (preg_match($deleteCallPattern, $content, $deleteCallMatch) !== 1) {
            return false;
        }
        $deleteCall = $deleteCallMatch[0];
        $rowAlias = $deleteCallMatch['row'];

        $downloadCall = ',e.jsx('.$menuItemAlias.',{asChild:!0,children:e.jsx("a",{href:"/plugins/backup-downloader/servers/"+'.$rowAlias.'.original.server_id+"/backups/"+'.$rowAlias.'.original.backup_id+"/files/"+'.$rowAlias.'.original.id+"/download",children:"Download"})})';
        $updatedContent = preg_replace('/'.preg_quote($deleteCall, '/').'/', $downloadCall.$deleteCall, $content, 1, $count);

        if (! is_string($updatedContent) || $count < 1) {
            return false;
        }

        return $this->writeFile($chunkPath, $updatedContent);
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

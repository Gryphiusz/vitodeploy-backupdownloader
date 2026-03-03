<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Enums\BackupFileStatus;
use App\Models\BackupFile;
use App\Models\Site;
use App\ServerFeatures\Action;
use App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Models\BackupDownloadLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GenerateBackupDownloadLink extends Action
{
    public function name(): string
    {
        return 'Generate Link';
    }

    public function active(): bool
    {
        return true;
    }

    public function form(): ?DynamicForm
    {
        $sites = $this->serverSites();

        $fields = [
            DynamicField::make('backup_downloader_info')
                ->alert()
                ->options(['type' => 'info'])
                ->description($this->backupSummaryText($sites)),
        ];

        $activeLink = $this->activeLink();
        if ($activeLink !== null) {
            $activeLinkDescription = sprintf(
                'Backup file #%d. Expires at %s.',
                $activeLink->backup_file_id,
                $activeLink->expires_at->toDateTimeString()
            );

            if ($activeLink->backupFile !== null) {
                $activeLinkDescription = sprintf(
                    '%s. Expires at %s.',
                    $this->backupFileLabel($activeLink->backupFile, $sites),
                    $activeLink->expires_at->toDateTimeString()
                );
            }

            $fields[] = DynamicField::make('backup_downloader_active_link')
                ->alert()
                ->label('Latest Generated Link')
                ->description($activeLinkDescription)
                ->link(
                    'Download Backup',
                    route('plugins.backup-downloader.download', ['token' => $activeLink->token])
                );
        }

        $options = $this->backupFileOptions($sites);
        if ($options === []) {
            $fields[] = DynamicField::make('backup_downloader_empty')
                ->alert()
                ->options(['type' => 'warning'])
                ->description('No downloadable backup files found yet. Run a backup first, then generate a link.');

            return DynamicForm::make($fields);
        }

        $fields[] = DynamicField::make('backup_file')
            ->select()
            ->label('Backup')
            ->options($options)
            ->default($options[0])
            ->description('Format: ID | type | source | site | created_at');

        $fields[] = DynamicField::make('expiration_minutes')
            ->select()
            ->label('Link Expiration (Minutes)')
            ->options(['5', '15', '30', '60'])
            ->default('15')
            ->description('The link is single-use and expires automatically.');

        return DynamicForm::make($fields);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'backup_file' => ['nullable', 'string', 'max:1000'],
            'backup_file_id' => ['nullable', 'integer'],
            'expiration_minutes' => ['required', 'integer', 'in:5,15,30,60'],
        ])->validate();

        $backupFileId = $this->extractBackupFileId((string) $request->input('backup_file', ''));
        if ($backupFileId === null && $request->filled('backup_file_id')) {
            $backupFileId = (int) $request->input('backup_file_id');
        }
        if ($backupFileId === null) {
            abort(422, 'Please select a backup file.');
        }

        $backupFile = BackupFile::query()
            ->with(['backup'])
            ->findOrFail($backupFileId);

        if ((int) $backupFile->backup->server_id !== (int) $this->server->id) {
            abort(422, 'Selected backup file does not belong to this server.');
        }

        if (! $backupFile->isAvailable()) {
            abort(422, 'Selected backup file is not available for download yet.');
        }

        $userId = Auth::id();
        if ($userId === null) {
            abort(403, 'You must be authenticated to generate download links.');
        }

        $minutes = (int) $request->input('expiration_minutes', 15);

        $link = BackupDownloadLink::query()->create([
            'token' => Str::random(64),
            'server_id' => $this->server->id,
            'user_id' => $userId,
            'backup_file_id' => $backupFile->id,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $url = route('plugins.backup-downloader.download', ['token' => $link->token]);
        $request->session()->flash(
            'success',
            sprintf(
                'Download link generated for backup file #%d (expires %s): %s',
                $backupFile->id,
                $link->expires_at->toDateTimeString(),
                $url
            )
        );
    }

    /**
     * @return array<int, string>
     */
    private function backupFileOptions(Collection $sites): array
    {
        return $this->recentAvailableBackupFiles()
            ->map(fn (BackupFile $file): string => $this->backupFileLabel($file, $sites))
            ->values()
            ->all();
    }

    private function backupSummaryText(Collection $sites): string
    {
        $files = $this->recentAvailableBackupFiles();
        if ($files->isEmpty()) {
            return 'No backup files found for this server.';
        }

        return "Available backups (ID | type | source | site | created_at):\n".$files
            ->map(fn (BackupFile $file): string => $this->backupFileLabel($file, $sites))
            ->implode("\n");
    }

    private function activeLink(): ?BackupDownloadLink
    {
        $userId = Auth::id();
        if ($userId === null) {
            return null;
        }

        return BackupDownloadLink::query()
            ->where('user_id', $userId)
            ->where('server_id', $this->server->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->with(['backupFile.backup.database'])
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, BackupFile>
     */
    private function recentAvailableBackupFiles(): Collection
    {
        return BackupFile::query()
            ->whereNotIn('status', [
                BackupFileStatus::CREATING->value,
                BackupFileStatus::FAILED->value,
                BackupFileStatus::DELETING->value,
            ])
            ->whereHas('backup', function ($query): void {
                $query->where('server_id', $this->server->id);
            })
            ->with(['backup.database'])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /**
     * @return Collection<int, Site>
     */
    private function serverSites(): Collection
    {
        return Site::query()
            ->where('server_id', $this->server->id)
            ->get(['id', 'domain', 'path', 'type_data']);
    }

    private function backupFileLabel(BackupFile $file, Collection $sites): string
    {
        $type = strtoupper($file->backup->type->value);
        $source = $this->backupSourceLabel($file);
        $site = $this->backupSiteLabel($file, $sites);
        $createdAt = $file->created_at?->toDateTimeString() ?? '-';

        return sprintf('#%d | %s | %s | %s | %s', $file->id, $type, $source, $site, $createdAt);
    }

    private function backupSourceLabel(BackupFile $file): string
    {
        if ($file->backup->type->value === 'database') {
            return 'DB:'.($file->backup->database?->name ?? '(deleted)');
        }

        $path = trim((string) $file->backup->path);
        if ($path === '') {
            return 'PATH:(unknown)';
        }

        return 'PATH:'.$path;
    }

    private function backupSiteLabel(BackupFile $file, Collection $sites): string
    {
        if ($file->backup->type->value === 'file') {
            $site = $this->matchSiteByPath((string) $file->backup->path, $sites);
            if ($site !== null) {
                return 'SITE:'.$site->domain;
            }

            return 'SITE:unknown';
        }

        $databaseName = (string) ($file->backup->database?->name ?? '');
        $site = $this->matchSiteByDatabaseName($databaseName, $sites);
        if ($site !== null) {
            return 'SITE:'.$site->domain;
        }

        return 'SITE:unknown';
    }

    private function matchSiteByPath(string $path, Collection $sites): ?Site
    {
        $normalizedPath = rtrim(trim($path), '/');
        if ($normalizedPath === '') {
            return null;
        }

        /** @var ?Site $site */
        $site = $sites
            ->filter(function (Site $site) use ($normalizedPath): bool {
                $sitePath = rtrim((string) $site->path, '/');
                if ($sitePath === '') {
                    return false;
                }

                return $normalizedPath === $sitePath || str_starts_with($normalizedPath, $sitePath.'/');
            })
            ->sortByDesc(fn (Site $site): int => strlen((string) $site->path))
            ->first();

        return $site;
    }

    private function matchSiteByDatabaseName(string $databaseName, Collection $sites): ?Site
    {
        $databaseName = trim($databaseName);
        if ($databaseName === '') {
            return null;
        }

        /** @var ?Site $site */
        $site = $sites->first(function (Site $site) use ($databaseName): bool {
            $typeData = is_array($site->type_data ?? null) ? $site->type_data : [];
            $siteDatabaseName = (string) ($typeData['database'] ?? '');

            return $siteDatabaseName !== '' && $siteDatabaseName === $databaseName;
        });

        return $site;
    }

    private function extractBackupFileId(string $selectedBackup): ?int
    {
        if (preg_match('/^\s*#?(\d+)\b/', $selectedBackup, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}

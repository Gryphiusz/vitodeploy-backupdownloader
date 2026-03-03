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
        return 'Backup Browser';
    }

    public function active(): bool
    {
        return true;
    }

    public function form(): ?DynamicForm
    {
        $sites = $this->serverSites();

        $fields = [
            DynamicField::make('backup_downloader_open_backups_page')
                ->alert()
                ->label('Open Server Backups')
                ->description('View all backups using Vito\'s built-in backups page, then come back here to generate a download link.')
                ->link(
                    'Open Backups Page',
                    route('plugins.backup-downloader.backups', ['server' => $this->server->id])
                ),
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
            $backupPath = (string) $file->backup->path;
            $site = $this->matchSiteByPath($backupPath, $sites);
            if ($site === null) {
                $site = $this->matchSiteByDomainInPath($backupPath, $sites);
            }
            if ($site !== null) {
                return 'SITE:'.$site->domain;
            }

            $domainFromPath = $this->extractDomainFromBackupPath($backupPath);
            if ($domainFromPath !== null) {
                return 'SITE:'.$domainFromPath.' (path)';
            }

            if ($sites->count() === 1) {
                /** @var Site $onlySite */
                $onlySite = $sites->first();

                return 'SITE:'.$onlySite->domain.' (?)';
            }

            return 'SITE:unknown';
        }

        $databaseName = (string) ($file->backup->database?->name ?? '');
        $site = $this->matchSiteByDatabaseName($databaseName, $sites);
        if ($site !== null) {
            return 'SITE:'.$site->domain;
        }

        if ($sites->count() === 1) {
            /** @var Site $onlySite */
            $onlySite = $sites->first();

            return 'SITE:'.$onlySite->domain.' (?)';
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
        $site = $sites->first(function (Site $site) use ($normalizedPath): bool {
            foreach ($this->sitePathCandidates($site) as $candidate) {
                if ($candidate === '' || $candidate === '/') {
                    continue;
                }

                if ($normalizedPath === $candidate || str_starts_with($normalizedPath, $candidate.'/')) {
                    return true;
                }
            }

            return false;
        });

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

            $candidates = [
                (string) ($typeData['database'] ?? ''),
                (string) ($typeData['db'] ?? ''),
                (string) ($typeData['db_name'] ?? ''),
                (string) ($typeData['database_name'] ?? ''),
                (string) ($typeData['dbName'] ?? ''),
                (string) ($typeData['DB_DATABASE'] ?? ''),
            ];

            foreach ($candidates as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && strcasecmp($candidate, $databaseName) === 0) {
                    return true;
                }
            }

            return false;
        });

        return $site;
    }

    /**
     * @return array<int, string>
     */
    private function sitePathCandidates(Site $site): array
    {
        $sitePath = rtrim(trim((string) $site->path), '/');
        if ($sitePath === '') {
            return [];
        }

        $candidates = [$sitePath];

        $parent = rtrim((string) dirname($sitePath), '/');
        if ($parent !== '' && $parent !== '.' && $parent !== '/') {
            $candidates[] = $parent;
        }

        $grandParent = rtrim((string) dirname($parent), '/');
        if ($grandParent !== '' && $grandParent !== '.' && $grandParent !== '/') {
            $candidates[] = $grandParent;
        }

        if (str_ends_with($sitePath, '/public')) {
            $withoutPublic = substr($sitePath, 0, -strlen('/public'));
            $withoutPublic = rtrim((string) $withoutPublic, '/');
            if ($withoutPublic !== '' && $withoutPublic !== '/') {
                $candidates[] = $withoutPublic;
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $candidates;
    }

    private function matchSiteByDomainInPath(string $backupPath, Collection $sites): ?Site
    {
        $domain = $this->extractDomainFromBackupPath($backupPath);
        if ($domain === null) {
            return null;
        }

        /** @var ?Site $site */
        $site = $sites->first(fn (Site $site): bool => strcasecmp((string) $site->domain, $domain) === 0);

        return $site;
    }

    private function extractDomainFromBackupPath(string $backupPath): ?string
    {
        $trimmed = trim($backupPath, '/');
        if ($trimmed === '') {
            return null;
        }

        $parts = explode('/', $trimmed);
        if (count($parts) >= 3 && $parts[0] === 'home') {
            $candidate = trim((string) $parts[2]);

            return $candidate !== '' ? $candidate : null;
        }

        return null;
    }

    private function extractBackupFileId(string $selectedBackup): ?int
    {
        if (preg_match('/^\s*#?(\d+)\b/', $selectedBackup, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}

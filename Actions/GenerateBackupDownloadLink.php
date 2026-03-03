<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Enums\BackupFileStatus;
use App\Models\BackupFile;
use App\Models\Plugin as PluginModel;
use App\Models\PluginError;
use App\Models\Site;
use App\ServerFeatures\Action;
use App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Models\BackupDownloadLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        try {
            return $this->buildForm();
        } catch (\Throwable $exception) {
            $this->reportRuntimePluginError($exception);

            Log::warning('Backup Downloader form rendering failed', [
                'server_id' => $this->server->id,
                'error' => $exception->getMessage(),
            ]);

            $errorLabel = Str::limit(
                sprintf('%s: %s', class_basename($exception), $exception->getMessage()),
                220
            );

            return DynamicForm::make([
                DynamicField::make('backup_downloader_form_error')
                    ->alert()
                    ->options(['type' => 'warning'])
                    ->label('Backup Downloader Temporary Issue')
                    ->description('Could not render backup list safely. '.$errorLabel),
            ]);
        }
    }

    private function buildForm(): DynamicForm
    {
        $sites = $this->serverSites();
        $allFiles = $this->recentAvailableBackupFiles();
        $selection = $this->selectionState();
        $siteOptions = $this->siteOptionsFromBackups($sites, $allFiles);
        $selectedSite = $selection['site'] ?? ($siteOptions[0] ?? 'unknown');
        $selectedType = $selection['type'] ?? 'file';

        $openBackupsField = DynamicField::make('backup_downloader_open_backups_page')
            ->alert()
            ->label('Open Server Backups')
            ->description('View all backups in Vito\'s built-in backups page. Then come back here to generate the download link.');

        if ($this->backupsPageUrl() !== null) {
            $openBackupsField->link(
                'Open Backups Page',
                $this->backupsPageUrl()
            );
        }

        $fields = [$openBackupsField];

        $fields[] = DynamicField::make('backup_downloader_wizard_state')
            ->alert()
            ->options(['type' => 'info'])
            ->description('Select site, backup type, and backup file below, then submit once to generate the link.');

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
                ->description($activeLinkDescription);

            $fields[count($fields) - 1]->link(
                'Download Backup',
                $this->downloadUrl($activeLink->token)
            );
        }

        if ($allFiles->isEmpty()) {
            $fields[] = DynamicField::make('backup_downloader_empty')
                ->alert()
                ->options(['type' => 'warning'])
                ->description('No downloadable backup files found yet. Run a backup first, then generate a link.');

            return DynamicForm::make($fields);
        }

        $fields[] = DynamicField::make('site_key')
            ->select()
            ->label('Step 1: Select Site')
            ->options($siteOptions)
            ->default($selectedSite);

        $fields[] = DynamicField::make('backup_type')
            ->select()
            ->label('Step 2: Select Backup Type')
            ->options(['file', 'database'])
            ->default($selectedType);

        $options = $this->backupFileOptions($sites, $allFiles, $selectedSite, $selectedType);
        if ($options === []) {
            $fields[] = DynamicField::make('backup_downloader_filtered_empty')
                ->alert()
                ->options(['type' => 'warning'])
                ->description('No backups match the currently selected site/type. Change selection and submit once to refresh.');

            return DynamicForm::make($fields);
        }

        $fields[] = DynamicField::make('backup_file')
            ->select()
            ->label('Step 3: Select Backup File')
            ->options($options)
            ->default($options[0])
            ->description('Format: ID | source(path/db) | created_at. Must match selected site and type.');

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
            'site_key' => ['required', 'string', 'max:255'],
            'backup_type' => ['required', 'in:file,database'],
            'expiration_minutes' => ['nullable', 'integer', 'in:5,15,30,60'],
        ])->validate();

        $sites = $this->serverSites();
        $allFiles = $this->recentAvailableBackupFiles();
        if ($allFiles->isEmpty()) {
            abort(422, 'No downloadable backup files found yet.');
        }

        $selectedSite = Str::lower(trim((string) $request->input('site_key', '')));
        $selectedType = (string) $request->input('backup_type', '');

        $selection = $this->selectionState();
        if (($selection['site'] ?? null) === null || ($selection['type'] ?? null) === null) {
            $this->putSelectionState($request, $selectedSite, $selectedType);
            $selection = [
                'site' => $selectedSite,
                'type' => $selectedType,
            ];
        }

        $selectionChanged = ($selection['site'] ?? null) !== $selectedSite
            || ($selection['type'] ?? null) !== $selectedType;
        if ($selectionChanged) {
            $this->putSelectionState($request, $selectedSite, $selectedType);
            throw ValidationException::withMessages([
                'backup_file' => 'Selection updated. Backup list is now filtered. Choose a backup file and submit again.',
            ]);
        }

        $backupFileId = $this->extractBackupFileId((string) $request->input('backup_file', ''));
        if ($backupFileId === null && $request->filled('backup_file_id')) {
            $backupFileId = (int) $request->input('backup_file_id');
        }
        if ($backupFileId === null) {
            abort(422, 'Please select a backup file.');
        }

        $backupFile = BackupFile::query()
            ->with(['backup.database'])
            ->findOrFail($backupFileId);

        if ($backupFile->backup === null || (int) $backupFile->backup->server_id !== (int) $this->server->id) {
            abort(422, 'Selected backup file does not belong to this server.');
        }

        if (! $backupFile->isAvailable()) {
            abort(422, 'Selected backup file is not available for download yet.');
        }

        if ((string) $backupFile->backup->type->value !== $selectedType) {
            abort(422, 'Selected backup file does not match the selected backup type.');
        }

        $resolvedSiteKey = $this->siteKeyForBackupFile($backupFile, $sites);
        if ($resolvedSiteKey !== $selectedSite) {
            abort(422, 'Selected backup file does not match the selected site.');
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

        $this->putSelectionState($request, $selectedSite, $selectedType);
        $request->session()->flash(
            'success',
            sprintf(
                'Download link generated for backup file #%d (expires %s): %s',
                $backupFile->id,
                $link->expires_at->toDateTimeString(),
                $this->downloadUrl($link->token)
            )
        );
    }

    /**
     * @return array<int, string>
     */
    private function backupFileOptions(Collection $sites, Collection $allFiles, string $selectedSite, string $selectedType): array
    {
        return $allFiles
            ->filter(function (BackupFile $file) use ($sites, $selectedSite, $selectedType): bool {
                if ($file->backup === null) {
                    return false;
                }

                if ((string) $file->backup->type->value !== $selectedType) {
                    return false;
                }

                return $this->siteKeyForBackupFile($file, $sites) === $selectedSite;
            })
            ->map(fn (BackupFile $file): string => $this->backupFileLabel($file, $sites))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function siteOptionsFromBackups(Collection $sites, Collection $allFiles): array
    {
        $siteOptions = $allFiles
            ->map(fn (BackupFile $file): string => $this->siteKeyForBackupFile($file, $sites))
            ->unique()
            ->values()
            ->all();

        sort($siteOptions);

        if ($siteOptions === []) {
            return ['unknown'];
        }

        return $siteOptions;
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
            ->whereHas('backupFile.backup')
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
        try {
            return Site::query()
                ->where('server_id', $this->server->id)
                ->get(['id', 'domain', 'path', 'type_data']);
        } catch (\Throwable) {
            // Fallback for legacy/invalid site type_data payloads.
            return Site::query()
                ->where('server_id', $this->server->id)
                ->get(['id', 'domain', 'path']);
        }
    }

    private function backupFileLabel(BackupFile $file, Collection $sites): string
    {
        $source = $this->backupSourceLabel($file);
        $createdAt = $file->created_at?->toDateTimeString() ?? '-';

        return sprintf('#%d | %s | %s', $file->id, $source, $createdAt);
    }

    private function backupSourceLabel(BackupFile $file): string
    {
        if ($file->backup === null) {
            return 'SOURCE:(backup-missing)';
        }

        if ($file->backup->type->value === 'database') {
            $databaseId = (int) ($file->backup->database_id ?? 0);
            $databaseName = (string) ($file->backup->database?->name ?? '(deleted)');

            return $databaseId > 0
                ? sprintf('DB#%d:%s', $databaseId, $databaseName)
                : 'DB:'.$databaseName;
        }

        $path = trim((string) $file->backup->path);
        if ($path === '') {
            return 'PATH:(unknown)';
        }

        return 'PATH:'.$path;
    }

    private function backupSiteLabel(BackupFile $file, Collection $sites): string
    {
        $siteKey = $this->siteKeyForBackupFile($file, $sites);

        return $siteKey === 'unknown'
            ? 'SITE:unknown'
            : 'SITE:'.$siteKey;
    }

    private function siteKeyForBackupFile(BackupFile $file, Collection $sites): string
    {
        if ($file->backup === null) {
            return 'unknown';
        }

        if ($file->backup->type->value === 'file') {
            $backupPath = (string) $file->backup->path;
            $site = $this->matchSiteByPath($backupPath, $sites);
            if ($site === null) {
                $site = $this->matchSiteByDomainInPath($backupPath, $sites);
            }
            if ($site !== null) {
                return Str::lower((string) $site->domain);
            }

            $domainFromPath = $this->extractDomainFromBackupPath($backupPath);
            if ($domainFromPath !== null) {
                return Str::lower($domainFromPath);
            }

            if ($sites->count() === 1) {
                /** @var Site $onlySite */
                $onlySite = $sites->first();

                return Str::lower((string) $onlySite->domain);
            }

            return 'unknown';
        }

        $databaseName = (string) ($file->backup->database?->name ?? '');
        $site = $this->matchSiteByDatabaseName($databaseName, $sites);
        if ($site !== null) {
            return Str::lower((string) $site->domain);
        }

        if ($sites->count() === 1) {
            /** @var Site $onlySite */
            $onlySite = $sites->first();

            return Str::lower((string) $onlySite->domain);
        }

        return 'unknown';
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

                if ($normalizedPath === $candidate || Str::startsWith($normalizedPath, $candidate.'/')) {
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

        if (Str::endsWith($sitePath, '/public')) {
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

    private function downloadUrl(string $token): string
    {
        if (Route::has('plugins.backup-downloader.download')) {
            return route('plugins.backup-downloader.download', ['token' => $token]);
        }

        return url('/plugins/backup-downloader/download/'.$token);
    }

    private function backupsPageUrl(): string
    {
        if (Route::has('backups')) {
            return route('backups', ['server' => $this->server->id]);
        }

        if (Route::has('plugins.backup-downloader.backups')) {
            return route('plugins.backup-downloader.backups', ['server' => $this->server->id]);
        }

        return url('/servers/'.$this->server->id.'/backups');
    }

    /**
     * @return array{site:?string,type:?string}
     */
    private function selectionState(): array
    {
        try {
            $state = request()->session()->get($this->selectionStateKey(), []);
        } catch (\Throwable) {
            $state = [];
        }

        if (! is_array($state)) {
            $state = [];
        }

        $site = isset($state['site']) ? Str::lower(trim((string) $state['site'])) : null;
        $type = isset($state['type']) ? trim((string) $state['type']) : null;
        if (! in_array($type, ['file', 'database'], true)) {
            $type = null;
        }

        return [
            'site' => $site !== '' ? $site : null,
            'type' => $type,
        ];
    }

    private function putSelectionState(Request $request, string $site, string $type): void
    {
        $request->session()->put($this->selectionStateKey(), [
            'site' => Str::lower(trim($site)),
            'type' => $type,
        ]);
    }

    private function selectionStateKey(): string
    {
        $userId = Auth::id() ?? 0;

        return sprintf('backup_downloader_selection.server_%d.user_%d', $this->server->id, $userId);
    }

    private function reportRuntimePluginError(\Throwable $exception): void
    {
        try {
            $plugin = PluginModel::query()
                ->where('namespace', 'App\\Vito\\Plugins\\Gryphiusz\\VitodeployBackupdownloader\\Plugin')
                ->first();

            if ($plugin !== null) {
                PluginError::createFromException($exception, $plugin);
            }
        } catch (\Throwable) {
            // Ignore secondary failures while reporting plugin runtime errors.
        }
    }

    private function extractBackupFileId(string $selectedBackup): ?int
    {
        if (preg_match('/^\s*#?(\d+)\b/', $selectedBackup, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}

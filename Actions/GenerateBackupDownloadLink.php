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
        $wizard = $this->wizardState();
        $selectedSite = $wizard['site'] ?? null;
        $selectedType = $wizard['type'] ?? null;

        $openBackupsField = DynamicField::make('backup_downloader_open_backups_page')
            ->alert()
            ->label('Open Server Backups')
            ->description('View all backups in Vito\'s built-in backups page. Then come back here to generate the download link.');

        if (Route::has('plugins.backup-downloader.backups')) {
            $openBackupsField->link(
                'Open Backups Page',
                route('plugins.backup-downloader.backups', ['server' => $this->server->id])
            );
        }

        $fields = [$openBackupsField];

        $fields[] = DynamicField::make('backup_downloader_wizard_state')
            ->alert()
            ->options(['type' => 'info'])
            ->description($this->wizardStatusText($selectedSite, $selectedType));

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

            if (Route::has('plugins.backup-downloader.download')) {
                $fields[count($fields) - 1]->link(
                    'Download Backup',
                    route('plugins.backup-downloader.download', ['token' => $activeLink->token])
                );
            }
        }

        if ($allFiles->isEmpty()) {
            $fields[] = DynamicField::make('backup_downloader_empty')
                ->alert()
                ->options(['type' => 'warning'])
                ->description('No downloadable backup files found yet. Run a backup first, then generate a link.');

            return DynamicForm::make($fields);
        }

        if ($selectedSite === null) {
            $siteOptions = $this->siteOptionsFromBackups($sites, $allFiles);
            $fields[] = DynamicField::make('site_key')
                ->select()
                ->label('Step 1: Select Site')
                ->options($siteOptions)
                ->default($siteOptions[0] ?? null)
                ->description('Submit to continue to Step 2.');

            return DynamicForm::make($fields);
        }

        if ($selectedType === null) {
            $fields[] = DynamicField::make('backup_downloader_selected_site')
                ->alert()
                ->label('Selected Site')
                ->description($selectedSite);

            $fields[] = DynamicField::make('backup_type')
                ->select()
                ->label('Step 2: Select Backup Type')
                ->options(['file', 'database'])
                ->default('file')
                ->description('Submit to continue to Step 3.');

            $fields[] = DynamicField::make('start_over')
                ->checkbox()
                ->label('Start Over')
                ->default(false);

            return DynamicForm::make($fields);
        }

        $fields[] = DynamicField::make('backup_downloader_selected_site_type')
            ->alert()
            ->label('Current Selection')
            ->description(sprintf('site=%s | type=%s', $selectedSite, strtoupper($selectedType)));

        $options = $this->backupFileOptions($sites, $allFiles, $selectedSite, $selectedType);
        if ($options === []) {
            $fields[] = DynamicField::make('backup_downloader_filtered_empty')
                ->alert()
                ->options(['type' => 'warning'])
                ->description('No backup files match this site/type. Tick "Start Over" and submit.');
            $fields[] = DynamicField::make('start_over')
                ->checkbox()
                ->label('Start Over')
                ->default(false);

            return DynamicForm::make($fields);
        }

        $fields[] = DynamicField::make('backup_file')
            ->select()
            ->label('Step 3: Select Backup File')
            ->options($options)
            ->default($options[0])
            ->description('Format: ID | type | source | site | created_at');

        $fields[] = DynamicField::make('expiration_minutes')
            ->select()
            ->label('Link Expiration (Minutes)')
            ->options(['5', '15', '30', '60'])
            ->default('15')
            ->description('The link is single-use and expires automatically.');

        $fields[] = DynamicField::make('start_over')
            ->checkbox()
            ->label('Start Over')
            ->default(false);

        return DynamicForm::make($fields);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'backup_file' => ['nullable', 'string', 'max:1000'],
            'backup_file_id' => ['nullable', 'integer'],
            'site_key' => ['nullable', 'string', 'max:255'],
            'backup_type' => ['nullable', 'in:file,database'],
            'start_over' => ['nullable'],
            'expiration_minutes' => ['nullable', 'integer', 'in:5,15,30,60'],
        ])->validate();

        if (filter_var($request->input('start_over', false), FILTER_VALIDATE_BOOL)) {
            $this->clearWizardState($request);
            $request->session()->flash('info', 'Backup selection wizard reset.');

            return;
        }

        $sites = $this->serverSites();
        $allFiles = $this->recentAvailableBackupFiles();
        if ($allFiles->isEmpty()) {
            abort(422, 'No downloadable backup files found yet.');
        }

        $wizard = $this->wizardState();
        $selectedSite = $wizard['site'] ?? null;
        $selectedType = $wizard['type'] ?? null;

        // Step 1: site
        if ($selectedSite === null) {
            $siteKey = trim((string) $request->input('site_key', ''));
            if ($siteKey === '') {
                abort(422, 'Please select a site.');
            }

            $this->putWizardState($request, $siteKey, null);
            $request->session()->flash('info', "Step 1 complete: site '{$siteKey}' selected. Choose backup type next.");

            return;
        }

        // Step 2: type
        if ($selectedType === null) {
            $backupType = (string) $request->input('backup_type', '');
            if (! in_array($backupType, ['file', 'database'], true)) {
                abort(422, 'Please select a backup type.');
            }

            $this->putWizardState($request, $selectedSite, $backupType);
            $request->session()->flash('info', sprintf(
                'Step 2 complete: %s backups selected. Choose backup file next.',
                strtoupper($backupType)
            ));

            return;
        }

        // Step 3: backup file
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

        if (Route::has('plugins.backup-downloader.download')) {
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
        } else {
            $request->session()->flash(
                'success',
                sprintf('Download token generated for backup file #%d (expires %s).', $backupFile->id, $link->expires_at->toDateTimeString())
            );
        }
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

    private function wizardStatusText(?string $selectedSite, ?string $selectedType): string
    {
        if ($selectedSite === null) {
            return 'Wizard step 1/3: select site and submit.';
        }

        if ($selectedType === null) {
            return sprintf('Wizard step 2/3: site=%s selected. Choose backup type and submit.', $selectedSite);
        }

        return sprintf('Wizard step 3/3: site=%s and type=%s selected. Choose backup file and generate link.', $selectedSite, strtoupper($selectedType));
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
        $type = strtoupper((string) ($file->backup?->type?->value ?? 'unknown'));
        $source = $this->backupSourceLabel($file);
        $site = $this->backupSiteLabel($file, $sites);
        $createdAt = $file->created_at?->toDateTimeString() ?? '-';

        return sprintf('#%d | %s | %s | %s | %s', $file->id, $type, $source, $site, $createdAt);
    }

    private function backupSourceLabel(BackupFile $file): string
    {
        if ($file->backup === null) {
            return 'SOURCE:(backup-missing)';
        }

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

    /**
     * @return array{site:?string,type:?string}
     */
    private function wizardState(): array
    {
        try {
            $state = request()->session()->get($this->wizardStateKey(), []);
        } catch (\Throwable) {
            $state = [];
        }

        if (! is_array($state)) {
            $state = [];
        }

        $site = isset($state['site']) ? trim((string) $state['site']) : null;
        $type = isset($state['type']) ? trim((string) $state['type']) : null;
        if (! in_array($type, ['file', 'database'], true)) {
            $type = null;
        }

        return [
            'site' => $site !== '' ? $site : null,
            'type' => $type,
        ];
    }

    private function putWizardState(Request $request, ?string $site, ?string $type): void
    {
        $request->session()->put($this->wizardStateKey(), [
            'site' => $site,
            'type' => $type,
        ]);
    }

    private function clearWizardState(Request $request): void
    {
        $request->session()->forget($this->wizardStateKey());
    }

    private function wizardStateKey(): string
    {
        $userId = Auth::id() ?? 0;

        return sprintf('backup_downloader_wizard.server_%d.user_%d', $this->server->id, $userId);
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

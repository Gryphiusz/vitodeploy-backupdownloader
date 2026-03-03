<?php

namespace App\Vito\Plugins\Arnobolt\BackupDownloader\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Enums\BackupFileStatus;
use App\Models\BackupFile;
use App\ServerFeatures\Action;
use App\Vito\Plugins\Arnobolt\BackupDownloader\Models\BackupDownloadLink;
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
        $fields = [
            DynamicField::make('backup_downloader_info')
                ->alert()
                ->options(['type' => 'info'])
                ->description($this->backupSummaryText()),
        ];

        $activeLink = $this->activeLink();
        if ($activeLink !== null) {
            $fields[] = DynamicField::make('backup_downloader_active_link')
                ->alert()
                ->label('Latest Generated Link')
                ->description(sprintf(
                    'Backup file #%d. Expires at %s.',
                    $activeLink->backup_file_id,
                    $activeLink->expires_at->toDateTimeString()
                ))
                ->link(
                    'Download Backup',
                    route('plugins.backup-downloader.download', ['token' => $activeLink->token])
                );
        }

        $options = $this->backupFileOptions();
        if ($options === []) {
            $fields[] = DynamicField::make('backup_downloader_empty')
                ->alert()
                ->options(['type' => 'warning'])
                ->description('No downloadable backup files found yet. Run a backup first, then generate a link.');

            return DynamicForm::make($fields);
        }

        $fields[] = DynamicField::make('backup_file_id')
            ->select()
            ->label('Backup File ID')
            ->options($options)
            ->default($options[0])
            ->description('Select the backup file ID you want to download.');

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
            'backup_file_id' => ['required', 'integer', 'exists:backup_files,id'],
            'expiration_minutes' => ['required', 'integer', 'in:5,15,30,60'],
        ])->validate();

        $backupFile = BackupFile::query()
            ->with(['backup'])
            ->findOrFail((int) $request->input('backup_file_id'));

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
    private function backupFileOptions(): array
    {
        return $this->recentAvailableBackupFiles()
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->values()
            ->all();
    }

    private function backupSummaryText(): string
    {
        $files = $this->recentAvailableBackupFiles();
        if ($files->isEmpty()) {
            return 'No backup files found for this server.';
        }

        return $files
            ->map(function (BackupFile $file): string {
                $extension = $file->backup->type->value === 'database' ? '.zip' : '.tar.gz';
                $name = $file->name.$extension;

                return sprintf(
                    '#%d | %s | status=%s | %s',
                    $file->id,
                    $name,
                    $file->status->value,
                    $file->created_at?->toDateTimeString() ?? '-'
                );
            })
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
}

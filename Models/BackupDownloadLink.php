<?php

namespace App\Vito\Plugins\Arnobolt\BackupDownloader\Models;

use App\Models\BackupFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $token
 * @property int $server_id
 * @property int $user_id
 * @property int $backup_file_id
 * @property \Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $used_at
 */
class BackupDownloadLink extends Model
{
    protected $table = 'backup_downloader_links';

    protected $fillable = [
        'token',
        'server_id',
        'user_id',
        'backup_file_id',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'server_id' => 'integer',
        'user_id' => 'integer',
        'backup_file_id' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * @return BelongsTo<BackupFile, covariant $this>
     */
    public function backupFile(): BelongsTo
    {
        return $this->belongsTo(BackupFile::class, 'backup_file_id');
    }
}

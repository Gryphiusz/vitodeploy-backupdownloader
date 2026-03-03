<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployBackupdownloader\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

class BackupBrowserController extends Controller
{
    public function __invoke(Server $server): RedirectResponse
    {
        $this->authorize('view', [$server]);

        return redirect()->route('backups', [
            'server' => $server->id,
        ]);
    }
}

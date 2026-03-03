# Backup Downloader Plugin

Adds a `Download` option to the backup-file row menu in Vito and serves secure one-time downloads.

## Features

- Patches Vito core file menu: `resources/js/pages/backups/components/file-columns.tsx`
- Adds row action in the 3-dots menu: `Download`
- Action creates a one-time, short-lived token and immediately redirects to the download endpoint
- Token is user-scoped and marked used after successful download
- Auto-reapplies the menu patch when the plugin boots (helps after Vito updates overwrite core file)

## Installation (Local Development)

Place this plugin at:

`app/Vito/Plugins/Gryphiusz/VitodeployBackupdownloader`

Then in Vito UI:

1. Go to `Admin > Plugins > Discover`
2. Install and enable `Backup Downloader`
3. Rebuild frontend assets in Vito root if needed: `npm run build`

## Usage

1. Open a server
2. Go to `Backups`
3. Open backup `files`
4. Click the 3-dots menu on a backup file row
5. Click `Download`

## Notes

- Links are single-use and user-scoped.
- If Vito is updated and overwrites the core file, this plugin patches it again on next boot.
- If your installation uses prebuilt frontend assets, run `npm run build` after update/patch.
- Plugin data is stored in `backup_downloader_links`.

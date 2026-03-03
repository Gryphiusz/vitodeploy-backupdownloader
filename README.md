# Backup Downloader Plugin

Adds a `Download` option to the backup-file row menu in Vito.

## Features

- Patches Vito core file menu: `resources/js/pages/backups/components/file-columns.tsx`
- Adds row action in the 3-dots menu: `Download`
- Action downloads directly via Vito's authenticated backend flow for the selected backup file
- Auto-reapplies the menu patch when the plugin boots (helps after Vito updates overwrite core file)

## Installation (Local Development)

Place this plugin at:

`app/Vito/Plugins/Gryphiusz/VitodeployBackupdownloader`

Then in Vito UI:

1. Go to `Admin > Plugins > Discover`
2. Install and enable `Backup Downloader`
3. No frontend build required (plugin patches source and prebuilt assets automatically)

## Usage

1. Open a server
2. Go to `Backups`
3. Open backup `files`
4. Click the 3-dots menu on a backup file row
5. Click `Download`

## Notes

- If Vito is updated and overwrites the core file, this plugin patches it again on next boot.
- No frontend build is required: plugin patches both source file and prebuilt `public/build` chunk when possible.
- Plugin data is stored in `backup_downloader_links`.

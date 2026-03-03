# Backup Downloader Plugin

Adds a VitoDeploy server feature to generate temporary, single-use links for downloading backup files.

## Features

- Registers server feature: `Backup Downloader`
- Action: `Backup Browser`
- Adds a direct link to Vito's native backups page for the current server
- Uses a single modal form with wizard-style fields: site -> backup type -> backup file
- Generates short-lived token links tied to the current user and server
- Downloads backup files through Vito using provider -> server tmp -> Vito tmp -> browser flow

## Installation (Local Development)

Place this plugin at:

`app/Vito/Plugins/Gryphiusz/VitodeployBackupdownloader`

Then in Vito UI:

1. Go to `Admin > Plugins > Discover`
2. Install and enable `Backup Downloader`

## Usage

1. Open a server
2. Go to `Features > Backup Downloader`
3. Run `Backup Browser`
4. Use `Open Backups Page` to inspect backups in Vito's original backups UI
5. In the action modal select site and backup type, then submit once if you changed them (this refreshes the filtered backup-file list)
6. Select backup file (`ID | source(path/db) | created_at`) and expiration
7. Submit to generate the download link (URL is shown in the success message)

## Notes

- Links are single-use and user-scoped.
- If no backup files are listed, run a backup from the server backup page first.
- Plugin data is stored in `backup_downloader_links`.

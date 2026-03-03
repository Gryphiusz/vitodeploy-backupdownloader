# Backup Downloader Plugin

Adds a VitoDeploy server feature to generate temporary, single-use links for downloading backup files.

## Features

- Registers server feature: `Backup Downloader`
- Action: `Backup Browser`
- Adds a direct link to Vito's native backups page for the current server
- Uses a single modal form with a 7-day backup calendar overview and direct backup picker
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
5. In the action modal use the last-7-days overview to see available backups per day
6. Select backup file (`#ID | TYPE | SITE | SOURCE | created_at`) and expiration
7. Submit to generate the download link (URL is shown in the success message)

## Notes

- Links are single-use and user-scoped.
- If no backup files are listed, run a backup from the server backup page first.
- Plugin data is stored in `backup_downloader_links`.

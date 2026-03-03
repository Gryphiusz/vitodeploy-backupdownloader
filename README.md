# Backup Downloader Plugin

Adds a VitoDeploy server feature to generate temporary, single-use links for downloading backup files.

## Features

- Registers server feature: `Backup Downloader`
- Action: `Generate Link`
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
3. Run `Generate Link`
4. Select a `Backup File ID` and expiration window
5. Open the generated link before it expires

## Notes

- Links are single-use and user-scoped.
- If no backup files are listed, run a backup from the server backup page first.
- Plugin data is stored in `backup_downloader_links`.

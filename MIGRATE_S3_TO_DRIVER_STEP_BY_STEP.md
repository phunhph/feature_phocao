# Migrate image storage from S3 to local / driver (step-by-step)

This document explains how to migrate the application from storing files on S3 to using another filesystem driver (local/public or Google Drive), and how to handle the case where the DB currently stores filenames but the driver (e.g. Google Drive) identifies files by `id`.

## Summary of goals
- Keep backward compatibility with existing DB values (filenames).
- When possible, store driver-specific identifiers (e.g. `fileId` for Google Drive) for better performance.
- Provide a get/resolver layer that can return a public URL for both local and Drive-backed files.

## Pre-flight checklist
- Backup your database and storage (important):

```powershell
# backup DB (example, change to your DB backup routine)
mysqldump -u root qhdn > qhdn-backup.sql

# ensure current storage files are saved
robocopy .\storage \\backup-server\storage-backup /E
```

- Ensure `FILESYSTEM_DISK` in `.env` is set to the default you want (e.g. `local` or `public`).
- Ensure `php artisan storage:link` has been run if you will use the `public` disk.

## High-level plan
1. Add a small service to interact with Google Drive (if using Google).
2. Update the upload trait to return a driver-specific identifier (filename for local, fileId for Google Drive) and additionally store a mapping (cache/DB) when you can't change DB schema.
3. Update the image cast / formatters to resolve filenames to public URLs: check `public` first, then try to resolve on Google Drive by name (cached), and finally return fallback value.
4. Update delete logic (delete by name for public, by id for Drive).
5. Optionally: add a migration to store `image_id` or `image_ref` for new uploads.

## Detailed steps

### 1) Quick configuration checks

- Confirm `config/filesystems.php` contains `public` disk and, if needed, `google` disk as in the project.
- Confirm Google Drive env vars are set in `.env` (`GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REFRESH_TOKEN`, `GOOGLE_DRIVE_FOLDER_ID`).

### 2) Implement a small Google Drive service (recommended)

Create `app/Services/GoogleDriveService.php` (example):

```php
<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;

class GoogleDriveService
{
    protected $drive;
    protected $folderId;

    public function __construct()
    {
        $client = new \Google\Client();
        $client->setClientId(config('filesystems.disks.google.clientId'));
        $client->setClientSecret(config('filesystems.disks.google.clientSecret'));
        $client->refreshToken(config('filesystems.disks.google.refreshToken'));
        $this->drive = new \Google\Service\Drive($client);
        $this->folderId = config('filesystems.disks.google.folderId');
    }

    public function uploadContent(string $filename, $content)
    {
        $file = new \Google\Service\Drive\DriveFile();
        $file->setName($filename);
        if ($this->folderId) $file->setParents([$this->folderId]);
        $created = $this->drive->files->create($file, [
            'data' => $content,
            'mimeType' => 'application/octet-stream',
            'uploadType' => 'multipart'
        ]);

        // set public permission
        try {
            $perm = new \Google\Service\Drive\Permission();
            $perm->setRole('reader');
            $perm->setType('anyone');
            $this->drive->permissions->create($created->id, $perm);
        } catch (\Throwable $e) {
            // ignore
        }

        // cache mapping filename => id
        Cache::put('gdrive:name_to_id:' . md5($filename), $created->id, now()->addHours(12));
        return $created->id;
    }

    public function findIdByName(string $filename)
    {
        $key = 'gdrive:name_to_id:' . md5($filename);
        $id = Cache::get($key);
        if ($id) return $id;

        // search
        $qParts = ["name = '" . addslashes($filename) . "'", "trashed = false"];
        if ($this->folderId) $qParts[] = "'{$this->folderId}' in parents";
        $params = ['q' => implode(' and ', $qParts), 'pageSize' => 1, 'fields' => 'files(id,name,webViewLink,webContentLink)'];
        $list = $this->drive->files->listFiles($params);
        if (!empty($list->files) && count($list->files) > 0) {
            $file = $list->files[0];
            Cache::put($key, $file->id, now()->addHours(12));
            return $file->id;
        }
        return null;
    }

    public function getPublicUrlById(string $id)
    {
        if (!$id) return null;
        // prefer webContentLink/webViewLink - otherwise construct download url
        try {
            $file = $this->drive->files->get($id, ['fields' => 'id,name,webViewLink,webContentLink']);
            if (!empty($file->webViewLink)) return $file->webViewLink;
        } catch (\Throwable $e) {
            // ignore
        }
        return "https://drive.google.com/uc?id={$id}&export=download";
    }

    public function deleteById(string $id)
    {
        if (!$id) return false;
        try {
            $this->drive->files->delete($id);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
```

Notes:
- The service caches `filename -> id` so you avoid repeated Drive list calls.

### 3) Update `TUploadImage` to return driver-specific identifier

- If you cannot change DB schema (you must keep storing `image` column as filename), change `TUploadImage::uploadFile()` to:
  - For `public` disk: upload as before and return the stored filename (the current behavior).
  - For `google` driver: upload, get `fileId` from the Drive API and return the original filename (to keep DB unchanged) *and* write the mapping `filename -> fileId` into cache (or a new DB table) so the getter can resolve it quickly.

Pseudo-change example (inside `uploadFile()`):

```php
// after creating $nameFile
if ($disk === 'google') {
    $service = new \App\Services\GoogleDriveService();
    $fileId = $service->uploadContent($nameFile, file_get_contents($file->getRealPath()));
    // still return filename so DB stays same, but mapping exists in cache
    return $nameFile;
} else {
    Storage::disk('public')->putFileAs('', $file, $nameFile);
    return $nameFile;
}
```

### 4) Update the getter/cast `FormatImageGet` to resolve filename -> public URL

Replace the current logic that directly calls `Storage::disk('s3')->temporaryUrl(...)` with the following process:

- If value is empty, return null.
- If `Storage::disk('public')->exists($value)` return `Storage::url($value)`.
- Else if Google disk configured, attempt to resolve fileId from cache or Drive by name (`GoogleDriveService::findIdByName($filename)`) and then return `GoogleDriveService::getPublicUrlById($id)`.
- Fallback: return the original value.

Example change (short):

```php
if (Storage::disk('public')->exists($value)) {
    return Storage::url($value);
}

if (!empty(config('filesystems.disks.google'))) {
    $g = new \App\Services\GoogleDriveService();
    $id = $g->findIdByName($value);
    if ($id) return $g->getPublicUrlById($id);
}

return $value;
```

### 5) Deleting files

- When deleting, detect driver:
  - If file stored in `public`, delete with `Storage::disk('public')->delete($filename)`.
  - If using Google: try to find `fileId` via cache or `GoogleDriveService::findIdByName()` and `GoogleDriveService::deleteById($fileId)`.

### 6) Optional DB migration (recommended long term)

- Add a new nullable column to store driver ID (e.g., `image_drive_id` or `image_ref`):

```php
// migration snippet
Schema::table('your_table', function (Blueprint $table) {
    $table->string('image_drive_id')->nullable()->after('image');
});
```

- Update upload flow to save both `image` (filename) and `image_drive_id` (when uploading to Drive). This removes the need to search by name later.

### 7) Tests & verification

- Create an upload test (or manual steps):
  1. Upload an image from admin UI.
  2. Verify DB `image` column contains the filename.
  3. If Google configured, check cache `gdrive:name_to_id:md5(filename)` contains an id (or `image_drive_id` is set when migrated).
  4. Access the page that uses `FormatImageGet` and confirm image URL is returned and loads in browser.

Run common commands:

```powershell
php artisan storage:link
php artisan config:cache
php artisan migrate
```

### 8) Performance & production tips

- Avoid listing Drive files on every request. Use a cache (Redis) to store `filename -> fileId` mapping.
- Prefer saving `fileId` at upload time when possible.
- If many legacy rows exist, consider a one-off migration script that iterates old rows, finds Drive `fileId` by name and writes it to `image_drive_id` or cache.

### 9) Rollback plan

- If something goes wrong, restore DB from backup and restore `storage` files backup.

## Appendices

- Example cache key convention used in examples: `gdrive:name_to_id:{md5(filename)}`
- Useful composer packages:
  - `google/apiclient` (for Google Drive API)
  - `masbug/flysystem-google-drive` or `nao-pon/flysystem-google-drive` (if you want Flysystem adapter)

If you want, I can now:
- A) Patch `app/Casts/FormatImageGet.php` with the getter + caching logic (quick change).
- B) Create the `app/Services/GoogleDriveService.php` file and update `TUploadImage.php` to call it (more complete).

Choose A or B and I'll implement the changes and tests.

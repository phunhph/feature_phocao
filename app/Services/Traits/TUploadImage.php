<?php

namespace App\Services\Traits;


use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Storage;

trait TUploadImage
{
    function uploadFile($file = null, $nameOld = null, $fileName = '', $content = null)
    {
        try {

            if (!$file) return false;
            if ($nameOld) if (Storage::disk('s3')->has($nameOld)) Storage::disk('s3')->delete($nameOld);
            $nameFile = empty($fileName) ? uniqid() . '-' . time() . '.' . $file->getClientOriginalExtension() : $fileName;
            if (!empty($content)) {
                Storage::disk('s3')->put($nameFile, $content);
            } else {
                Storage::disk('s3')->putFileAs('', $file, $nameFile);
            }
            return $nameFile;
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * Upload file to a driver storage (e.g., Google Drive) and try to return the driver file id.
     * Falls back to returning the stored filename when an id cannot be resolved.
     *
     * @param mixed $file UploadedFile instance or null
     * @param string|null $nameOld previous stored name/id to delete
     * @param string $fileName desired filename
     * @param mixed $content raw content (optional)
     * @param string $disk storage disk name (default 'google')
     * @return string|false file id (preferred) or filename or false on error
     */
    function uploadFileDriverStorage($file = null, $nameOld = null, $fileName = '', $content = null, $disk = 'google')
    {
        try {
            if (!$file && !$content) return false;

            $storage = Storage::disk($disk);
            if ($nameOld) {
                try {
                    if ($storage->exists($nameOld)) $storage->delete($nameOld);
                } catch (\Throwable $ex) {
                    // ignore deletion errors
                }
            }

            $nameFile = empty($fileName) ? uniqid() . '-' . time() . '.' . ($file ? $file->getClientOriginalExtension() : 'tmp') : $fileName;
            if (!empty($content)) {
                $storage->put($nameFile, $content);
            } else {
                $storage->putFileAs('', $file, $nameFile);
            }

            // Try to resolve a driver-specific file id (e.g., Google Drive file id)
            try {
                $adapter = $storage->getAdapter();
                if (method_exists($adapter, 'getService')) {
                    $service = $adapter->getService();
                    // Try to list files by the exact name we just uploaded
                    if (method_exists($service->files, 'listFiles')) {
                        $resp = $service->files->listFiles(['q' => "name='{$nameFile}'", 'fields' => 'files(id,name)']);
                        $files = $resp->getFiles();
                        if (count($files) > 0) {
                            return $files[0]->getId();
                        }
                    }
                }
            } catch (\Throwable $ex) {
                // ignore adapter/service resolution errors and fall back to filename
            }

            return $nameFile;
        } catch (\Throwable $th) {
            return false;
        }
    }

    protected function saveImgBase64($param, $nameOld = null)
    {
        try {
            if (!$param) return false;
            if ($nameOld) if (Storage::disk('s3')->has($nameOld)) Storage::disk('s3')->delete($nameOld);
            list($extension, $content) = explode(';', $param);
            $tmpExtension = explode('/', $extension);
            preg_match('/.([0-9]+) /', microtime(), $m);
            $fileName = sprintf('img%s%s.%s', date('YmdHis'), $m[1], $tmpExtension[1]);
            $content = explode(',', $content)[1];
            $storage = Storage::disk('s3');
            $storage->put('' . $fileName, base64_decode($content));
            return $fileName;
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * Save base64 image to a driver storage (e.g., Google Drive) and attempt to return the driver file id.
     * Falls back to returning the stored filename when an id cannot be resolved.
     *
     * @param string $param base64 data uri
     * @param string|null $nameOld previous stored name/id to delete
     * @param string $disk storage disk name (default 'google')
     * @return string|false
     */
    protected function saveImgBase64DriverStorage($param, $nameOld = null, $disk = 'google')
    {
        try {
            if (!$param) return false;
            $storage = Storage::disk($disk);
            if ($nameOld) {
                try {
                    if ($storage->exists($nameOld)) $storage->delete($nameOld);
                } catch (\Throwable $ex) {
                    // ignore deletion errors
                }
            }

            list($extension, $content) = explode(';', $param);
            $tmpExtension = explode('/', $extension);
            preg_match('/.([0-9]+) /', microtime(), $m);
            $fileName = sprintf('img%s%s.%s', date('YmdHis'), $m[1], $tmpExtension[1]);
            $content = explode(',', $content)[1];

            $storage->put('' . $fileName, base64_decode($content));

            // Try to resolve driver-specific id
            try {
                $adapter = $storage->getAdapter();
                if (method_exists($adapter, 'getService')) {
                    $service = $adapter->getService();
                    if (method_exists($service->files, 'listFiles')) {
                        $resp = $service->files->listFiles(['q' => "name='{$fileName}'", 'fields' => 'files(id,name)']);
                        $files = $resp->getFiles();
                        if (count($files) > 0) {
                            return $files[0]->getId();
                        }
                    }
                }
            } catch (\Throwable $ex) {
                // ignore
            }

            return $fileName;
        } catch (\Throwable $th) {
            return false;
        }
    }

    /* -------------------- Generic driver storage helpers -------------------- */

    /**
     * Check existence on a driver storage. Works with normal files or driver ids.
     */
    protected function hasDriverStorage($path, $disk = null)
    {
        $disk = $disk ?: config('filesystems.driver_storage_disk', 'google');
        try {
            $storage = Storage::disk($disk);
            // Prefer exists/has if available
            if (method_exists($storage, 'exists')) {
                return $storage->exists($path);
            }
            if (method_exists($storage, 'has')) {
                return $storage->has($path);
            }

            // Fallback: try adapter/service (Google Drive)
            $adapter = $storage->getAdapter();
            if (method_exists($adapter, 'getService')) {
                $service = $adapter->getService();
                try {
                    $service->files->get($path, ['fields' => 'id']);
                    return true;
                } catch (\Throwable $ex) {
                    return false;
                }
            }
        } catch (\Throwable $th) {
            return false;
        }
        return false;
    }

    /**
     * Delete file on driver storage. Accepts driver file id or path.
     */
    protected function deleteDriverStorage($path, $disk = null)
    {
        $disk = $disk ?: config('filesystems.driver_storage_disk', 'google');
        try {
            $storage = Storage::disk($disk);
            if (method_exists($storage, 'delete')) {
                return $storage->delete($path);
            }

            $adapter = $storage->getAdapter();
            if (method_exists($adapter, 'getService')) {
                $service = $adapter->getService();
                try {
                    $service->files->delete($path);
                    return true;
                } catch (\Throwable $ex) {
                    return false;
                }
            }
        } catch (\Throwable $th) {
            return false;
        }
        return false;
    }

    /**
     * Return a temporary URL for a driver-stored file or a direct URL when possible.
     */
    protected function temporaryUrlDriverStorage($path, $expiration, $disk = null)
    {
        $disk = $disk ?: config('filesystems.driver_storage_disk', 'google');
        try {
            $storage = Storage::disk($disk);
            if (method_exists($storage, 'temporaryUrl')) {
                return $storage->temporaryUrl($path, $expiration);
            }

            // Fallback for Google Drive: build webContentLink or webViewLink
            $adapter = $storage->getAdapter();
            if (method_exists($adapter, 'getService')) {
                $service = $adapter->getService();
                try {
                    $file = $service->files->get($path, ['fields' => 'id,name,webContentLink,webViewLink']);
                    if ($file && $file->getWebContentLink()) return $file->getWebContentLink();
                    if ($file && $file->getWebViewLink()) return $file->getWebViewLink();
                    return "https://drive.google.com/uc?export=download&id={$path}";
                } catch (\Throwable $ex) {
                    return null;
                }
            }
        } catch (\Throwable $th) {
            return null;
        }
        return null;
    }

    /**
     * Get raw content from driver storage (string|false).
     */
    protected function getDriverStorageContent($path, $disk = null)
    {
        $disk = $disk ?: config('filesystems.driver_storage_disk', 'google');
        try {
            $storage = Storage::disk($disk);
            if (method_exists($storage, 'get')) {
                return $storage->get($path);
            }

            // Fallback: Google Drive alt=media
            $adapter = $storage->getAdapter();
            if (method_exists($adapter, 'getService')) {
                $service = $adapter->getService();
                try {
                    $response = $service->files->get($path, ['alt' => 'media']);
                    // $response may be a Guzzle response
                    if (method_exists($response, 'getBody')) {
                        return $response->getBody()->getContents();
                    }
                    return (string)$response;
                } catch (\Throwable $ex) {
                    return false;
                }
            }
        } catch (\Throwable $th) {
            return false;
        }
        return false;
    }

    /**
     * Put content into driver storage (returns path/name or false).
     */
    protected function putDriverStorage($name, $content, $disk = null)
    {
        $disk = $disk ?: config('filesystems.driver_storage_disk', 'google');
        try {
            $storage = Storage::disk($disk);
            if (method_exists($storage, 'put')) {
                return $storage->put($name, $content) ? $name : false;
            }
            // Fallback: attempt put via adapter
            $storage->put($name, $content);
            return $name;
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * Put uploaded file as into driver storage (returns name or false).
     */
    protected function putFileAsDriverStorage($path, $file, $name, $disk = null)
    {
        $disk = $disk ?: config('filesystems.driver_storage_disk', 'google');
        try {
            $storage = Storage::disk($disk);
            if (method_exists($storage, 'putFileAs')) {
                return $storage->putFileAs($path, $file, $name);
            }
            // Fallback: save to temporary then put
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name;
            $fileContent = is_string($file) ? file_get_contents($file) : file_get_contents($file->getRealPath());
            file_put_contents($tmp, $fileContent);
            $storage->put($name, file_get_contents($tmp));
            @unlink($tmp);
            return $name;
        } catch (\Throwable $th) {
            return false;
        }
    }
}

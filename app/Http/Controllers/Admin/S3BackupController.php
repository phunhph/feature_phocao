<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Aws\S3\S3Client;

class S3BackupController extends Controller
{
    private function getS3Client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region'  => env('AWS_DEFAULT_REGION'),
            'credentials' => [
                'key'    => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
    }

    /**
     * 📦 API 1: Backup toàn bộ hoặc incremental dữ liệu từ S3 về VPS
     */
    public function backupS3(Request $request)
    {
        $s3 = $this->getS3Client();
        $bucket = env('AWS_BUCKET');
        $backupRoot = storage_path('backups');
        if (!is_dir($backupRoot)) mkdir($backupRoot, 0777, true);

        $todayDir = $backupRoot . '/' . date('Y-m-d');
        if (!is_dir($todayDir)) mkdir($todayDir, 0777, true);

        $markerFile = $backupRoot . '/last_backup_marker.json';
        $lastMarker = file_exists($markerFile) ? json_decode(file_get_contents($markerFile), true) : [];

        $params = ['Bucket' => $bucket];
        if (!empty($lastMarker['NextMarker'])) {
            $params['ContinuationToken'] = $lastMarker['NextMarker'];
        }

        $result = $s3->listObjectsV2($params);
        $downloaded = 0;

        if (!empty($result['Contents'])) {
            foreach ($result['Contents'] as $obj) {
                $key = $obj['Key'];
                $savePath = $todayDir . '/' . $key;
                $saveDir = dirname($savePath);
                if (!is_dir($saveDir)) mkdir($saveDir, 0777, true);

                $s3->getObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'SaveAs' => $savePath,
                ]);
                $downloaded++;
            }
        }

        // Ghi lại marker để lần sau chỉ tải bổ sung
        $markerData = [
            'NextMarker' => $result['NextContinuationToken'] ?? null,
            'LastBackupTime' => date('c')
        ];
        file_put_contents($markerFile, json_encode($markerData, JSON_PRETTY_PRINT));

        return response()->json([
            'success' => true,
            'message' => 'Backup S3 thành công!',
            'total_downloaded' => $downloaded,
            'backup_folder' => $todayDir,
        ]);
    }

    /**
     * ☁️ API 2: Đẩy bản backup từ VPS lên lại S3
     */
    public function uploadBackupToS3(Request $request)
{
    $backupDate = $request->input('date', date('Y-m-d'));
    $backupPath = storage_path("backups/{$backupDate}");

    if (!is_dir($backupPath)) {
        return response()->json([
            'success' => false,
            'message' => "Không tìm thấy thư mục backup: {$backupPath}"
        ], 404);
    }

    $s3 = $this->getS3Client();
    $bucket = env('AWS_BUCKET');
    $uploaded = 0;
    $uploadedFiles = [];

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($backupPath),
        \RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if ($file->isDir()) continue;

        $filePath = $file->getRealPath();
        $key = "restore/{$backupDate}/" . str_replace($backupPath . '/', '', $filePath);

        // Lấy MIME type (vd: image/jpeg)
        $contentType = mime_content_type($filePath);

        $s3->putObject([
            'Bucket' => $bucket,
            'Key'    => $key,
            'SourceFile' => $filePath,
            'ACL'    => 'public-read',  // ✅ Cho phép truy cập public
            'ContentType' => $contentType, // ✅ Giúp ảnh hiển thị đúng định dạng
        ]);

        $uploaded++;
        $uploadedFiles[] = "https://{$bucket}.s3." . env('AWS_DEFAULT_REGION') . ".amazonaws.com/{$key}";
    }

    return response()->json([
        'success' => true,
        'message' => "Upload backup lên S3 thành công!",
        'backup_date' => $backupDate,
        'total_uploaded' => $uploaded,
        'target_bucket' => $bucket,
        'file_urls' => $uploadedFiles, // ✅ trả về luôn link ảnh
    ]);
}
}

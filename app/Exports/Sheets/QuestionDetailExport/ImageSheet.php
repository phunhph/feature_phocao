<?php

namespace App\Exports\Sheets\QuestionDetailExport;

use App\Models\QuestionImage;
use App\Models\QuestionImageDriverStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ImageSheet implements WithTitle, WithHeadings, WithStyles, WithColumnWidths, WithMapping, FromQuery, WithDrawings, ShouldAutoSize
{

    private $questionId;

    public function __construct($questionId)
    {
        $this->questionId = $questionId;
    }

    public function title(): string
    {
        return 'Hình ảnh';
    }

    public function headings(): array
    {
        return [
            'Mã ảnh',
            'Ảnh',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text.
            1 => ['font' => ['bold' => true, 'size' => 16]],
        ];
    }

    public function map($image): array
    {
        return [
            $image->img_code
        ];
    }

    // public function query()
    // {
    //     return QuestionImage::query()->where('question_id', $this->questionId)->orderBy('id', 'asc');
    // }

    public function query()
    {
        return QuestionImageDriverStorage::query()->where('question_id', $this->questionId)->orderBy('id', 'asc');
    }

    public function columnWidths(): array
    {
        return [
            'A' => 25,
            'B' => 55,
        ];
    }
    // public function drawings()
    // {
    //     $drawings = [];
    //     $images = $this->query()->get();
    //     foreach ($images as $key => $image) {
    //         if (!$imageResource = @imagecreatefromstring(file_get_contents($image->path))) {
    //             Log::error('Error loading image: ' . $image->path);
    //             continue;
    //         }
    //         $drawing = new MemoryDrawing();
    //         $drawing->setName($image->img_code);
    //         $drawing->setImageResource($imageResource);
    //         $drawing->setHeight(100);
    //         $drawing->setCoordinates('B' . ($key + 2));

    //         $drawings[] = $drawing;
    //     }
    //     return $drawings;
    // }
    public function drawings()
    {
        $drawings = [];
        $images = $this->query()->get();
        
        // Lấy Google Drive service nếu có
        $driveService = null;
        try {
            if (Storage::disk('google')->exists('')) {
                $adapter = Storage::disk('google')->getAdapter();
                $driveService = $adapter->getService();
            }
        } catch (\Exception $e) {
            Log::error('Error getting Google Drive service: ' . $e->getMessage());
        }
        foreach ($images as $key => $image) {
            // Lấy raw path (ID) từ database (không qua cast)
            $fileId = $image->getRawOriginal('path');
            
            if (empty($fileId)) {
                Log::error('Image ID is empty for image: ' . $image->img_code);
                continue;
            }
            
            $imageContent = null;
            
            // Nếu có Google Drive service, download file từ Drive bằng ID
            if ($driveService) {
                try {
                    // Download file content từ Google Drive bằng ID
                    $file = $driveService->files->get($fileId, ['alt' => 'media']);
                    $imageContent = $file->getBody()->getContents();
                } catch (\Exception $e) {
                    Log::error('Error downloading image from Google Drive (ID: ' . $fileId . '): ' . $e->getMessage());
                    // Thử fallback: download từ URL
                    try {
                        $url = "https://drive.google.com/uc?id={$fileId}&export=download";
                        $imageContent = @file_get_contents($url);
                    } catch (\Exception $e2) {
                        Log::error('Error downloading image from URL (ID: ' . $fileId . '): ' . $e2->getMessage());
                        continue;
                    }
                }
            } else {
                // Fallback: thử download từ URL nếu không có service
                try {
                    $url = "https://drive.google.com/uc?id={$fileId}&export=download";
                    $imageContent = @file_get_contents($url);
                } catch (\Exception $e) {
                    Log::error('Error downloading image from URL (ID: ' . $fileId . '): ' . $e->getMessage());
                    continue;
                }
            }
            
            if (empty($imageContent)) {
                Log::error('Image content is empty for ID: ' . $fileId);
                continue;
            }
            
            // Tạo image resource từ nội dung
            if (!$imageResource = @imagecreatefromstring($imageContent)) {
                Log::error('Error creating image resource from ID: ' . $fileId);
                continue;
            }
            
            $drawing = new MemoryDrawing();
            $drawing->setName($image->img_code);
            $drawing->setImageResource($imageResource);
            $drawing->setHeight(100);
            $drawing->setCoordinates('B' . ($key + 2));

            $drawings[] = $drawing;
        }
        return $drawings;
    }
}

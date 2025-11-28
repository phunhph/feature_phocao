<?php

namespace App\Models;

use App\Casts\FormatImageGet;
use App\Services\Traits\UsesExamConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionImageDriverStorage extends Model
{
    use HasFactory, UsesExamConnection;

    protected $table = 'question_images_driver_storage';
    protected $casts = [
        'path' => FormatImageGet::class,
    ];
}

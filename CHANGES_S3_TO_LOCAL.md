# DANH SÁCH CÁC CHỖ CẦN THAY ĐỔI - S3 TO LOCAL DRIVER

## Tóm tắt
Để chuyển đổi từ S3 sang local driver, bạn cần thay đổi tất cả các lệnh `Storage::disk('s3')` thành `Storage::disk('public')` hoặc `Storage::disk('local')`.

---

## 1️⃣ UPLOAD FILES - CẦN THAY ĐỔI

### File: `app/Services/Traits/TUploadImage.php`
- **Dòng 16, 19, 21, 33, 39**: Thay tất cả `Storage::disk('s3')` thành `Storage::disk('public')`
  ```php
  // TRƯỚC
  if ($nameOld) if (Storage::disk('s3')->has($nameOld)) Storage::disk('s3')->delete($nameOld);
  Storage::disk('s3')->put($nameFile, $content);
  Storage::disk('s3')->putFileAs('', $file, $nameFile);
  
  // SAU
  if ($nameOld) if (Storage::disk('public')->has($nameOld)) Storage::disk('public')->delete($nameOld);
  Storage::disk('public')->put($nameFile, $content);
  Storage::disk('public')->putFileAs('', $file, $nameFile);
  ```

---

## 2️⃣ HIỂN THỊ IMAGES (GET URLs) - CẦN THAY ĐỔI

### File: `app/Casts/FormatImageGet.php`
- **Dòng 19**: Thay `Storage::disk('s3')->temporaryUrl()` thành `Storage::url()`
  ```php
  // TRƯỚC
  if (Storage::disk('s3')->has($value ?? "abc.jpg")) return Storage::disk('s3')->temporaryUrl($value, now()->addDays(7));
  
  // SAU - LOCAL không cần temporaryUrl
  if (Storage::disk('public')->has($value ?? "abc.jpg")) return Storage::url($value);
  ```

---

## 3️⃣ DELETE FILES - CẦN THAY ĐỔI

Tất cả các nơi kiểm tra và xóa file S3:

### File: `app/Services/Traits/TTeamContest.php`
- **Dòng 112, 195**: 
  ```php
  // TRƯỚC
  if (Storage::disk('s3')->has($fileImage)) Storage::disk('s3')->delete($fileImage);
  
  // SAU
  if (Storage::disk('public')->has($fileImage)) Storage::disk('public')->delete($fileImage);
  ```

### File: `app/Http/Controllers/Admin/TakeExamController.php`
- **Dòng 110, 121**: 
  ```php
  if (Storage::disk('s3')->has($takeExam->file_url ?? "Default")) Storage::disk('s3')->delete($takeExam->file_url);
  
  // Thay thành
  if (Storage::disk('public')->has($takeExam->file_url ?? "Default")) Storage::disk('public')->delete($takeExam->file_url);
  ```

### File: `app/Http/Controllers/Admin/RoundController.php`
- **Dòng 151-152, 178-179, 264**: 
  ```php
  if (Storage::disk('s3')->has($fileImage)) {
      Storage::disk('s3')->delete($fileImage);
  }
  
  // Thay thành
  if (Storage::disk('public')->has($fileImage)) {
      Storage::disk('public')->delete($fileImage);
  }
  ```

### File: `app/Http/Controllers/Admin/ContestController.php`
- **Dòng 217, 230, 231**: 
  ```php
  if ($this->storage::disk('s3')->has($filename)) $this->storage::disk('s3')->delete($filename);
  
  // Thay thành
  if ($this->storage::disk('public')->has($filename)) $this->storage::disk('public')->delete($filename);
  ```

---

## 4️⃣ RETURN FORMATTED URLS - CẦN THAY ĐỔI

### File: `app/Models/Round.php`
- **Dòng 59** (trong method `format()`):
  ```php
  // TRƯỚC
  "image" => Storage::disk('s3')->has($this->image) ? Storage::disk('s3')->temporaryUrl($this->image, now()->addMinutes(5)) : null,
  
  // SAU
  "image" => Storage::disk('public')->has($this->image) ? Storage::url($this->image) : null,
  ```

### File: `app/Http/Controllers/Admin/SupportController.php`
- **Dòng 43**:
  ```php
  // TRƯỚC
  $message = Storage::disk('s3')->temporaryUrl($namefile, now()->addSeconds(6000));
  
  // SAU
  $message = Storage::url($namefile);
  ```

### File: `app/Http/Controllers/Admin/CkeditorController.php`
- **Dòng 19**:
  ```php
  // TRƯỚC
  'url' => Storage::disk('s3')->temporaryUrl($nameFile, now()->addDays(7)),
  
  // SAU
  'url' => Storage::url($nameFile),
  ```

---

## 📝 LƯU Ý QUAN TRỌNG

1. **Local driver cấu hình**: Hiện tại `.env` đã có `FILESYSTEM_DISK=local` - điều này là tốt.

2. **Thư mục lưu trữ**: 
   - Local driver sẽ lưu file vào `storage/app/`
   - Public disk sẽ lưu vào `storage/app/public/`
   - Nên sử dụng `disk('public')` để file có thể truy cập public được

3. **Để public disk hoạt động**:
   ```bash
   php artisan storage:link
   ```
   Điều này tạo symbolic link từ `public/storage` -> `storage/app/public`

4. **URL hoạt động**: 
   - S3: `Storage::disk('s3')->temporaryUrl()` - cần expired time
   - Local: `Storage::url()` - trả về `/storage/filename`

5. **Thay đổi file config** (nếu cần):
   - Hiện tại `FILESYSTEM_DISK=local` đã đặt, không cần thay đổi

---

## 📋 DANH SÁCH FILE CẦN SỬA (5 file chính)

1. ✏️ `app/Services/Traits/TUploadImage.php` - Upload files (2 functions)
2. ✏️ `app/Casts/FormatImageGet.php` - Get image cast
3. ✏️ `app/Models/Round.php` - Model format method
4. ✏️ `app/Services/Traits/TTeamContest.php` - Delete files in trait
5. ✏️ `app/Http/Controllers/Admin/TakeExamController.php` - Delete files in controller
6. ✏️ `app/Http/Controllers/Admin/RoundController.php` - Delete files in controller
7. ✏️ `app/Http/Controllers/Admin/ContestController.php` - Delete files in controller
8. ✏️ `app/Http/Controllers/Admin/SupportController.php` - Return URL
9. ✏️ `app/Http/Controllers/Admin/CkeditorController.php` - Return URL in editor

---

## ⚡ Thứ tự khuyên cáo để sửa

1. **Trait TUploadImage** - Ưu tiên 1 (upload cơ bản)
2. **Trait TTeamContest** - Ưu tiên 2 (delete)
3. **Controllers delete** - Ưu tiên 3 (delete)
4. **Cast FormatImageGet** - Ưu tiên 4 (get URLs)
5. **Models & Controllers return URL** - Ưu tiên 5 (display)

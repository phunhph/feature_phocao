<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Services\Modules\MBlock\Block;
use App\Services\Modules\MSemeter\Semeter;
use App\Services\Redis\RedisService;
use App\Services\Traits\TResponse;
use App\Services\Traits\TUploadImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SemeterController extends Controller
{
    use TUploadImage, TResponse;

    private RedisService $redis;

    private int $cacheTTL = 86400;   // 24h
    private int $lockTTL = 5;        // 5s
    private int $maxWait = 3000;     // 3s
    private int $step = 200;         // 0.2s
    private string $cachePrefixAdmin = 'semeter_cache:admin:';
    private string $lockPrefixAdmin = 'semeter_lock:admin:';
    private string $cachePrefixClient = 'semeter_cache:client:';
    private string $lockPrefixClient = 'semeter_lock:client:';

    public function __construct(
        private Semeter $semeter,
        private Block $block
    ) {
        $this->redis = new RedisService();
    }

    private function getCacheKey(string $codeCampus): string
    {
        return $this->cachePrefixClient . $codeCampus;
    }

    private function getLockKey(string $codeCampus): string
    {
        return $this->lockPrefixClient . $codeCampus;
    }
    /**
     * Danh sách kỳ học (hiển thị giao diện)
     */
    public function index()
    {
        $cacheKey = $this->cachePrefixAdmin;
        $lockKey = $this->lockPrefixAdmin;

        // 1. Lấy cache trước
        if ($cached = $this->redis->get(key: $cacheKey)) {
            $semesters = $cached;
        }

        // 2. Cố gắng lock để tránh race condition
        if ($this->redis->lock($lockKey, $this->lockTTL)) {
            try {
                $semesters = $this->semeter->GetSemeter();

                if (!$semesters) {
                    return $this->responseApi(false, ['message' => 'Không có dữ liệu']);
                }

                $this->redis->set($cacheKey, $semesters, $this->cacheTTL);
            } finally {
                $this->redis->unlock($lockKey);
            }
        } else {
            // 3. Nếu lock không được, đợi cache được tạo
            $waited = 0;
            while ($waited < $this->maxWait) {
                usleep($this->step * 1000);
                $waited += $this->step;

                if ($cached = $this->redis->get($cacheKey)) {
                     $semesters = $cached;
                }
            }
        }

        //Tao mang
        $ids=[];
        foreach($semesters as $key=>$value){
            $ids[]=$value->id;
        }
        $block_id= $this->block->getAllIdBlockOne($ids)->pluck('id', 'id_semeter');
        $campusListQuery = Campus::query();
        if (!(auth()->user()->hasRole('super admin'))) {
            $campusListQuery->where('id', auth()->user()->campus_id);
        }
        $campusList = $campusListQuery->get();
        return view('pages.semeter.index',['setemer' => $semesters,'campusList' => $campusList,'id'=>$block_id]);
    }
    /**
     * API danh sách kỳ học
     */
    public function ListSemeter()
    {
        $cacheKey = $this->cachePrefixAdmin;
        $lockKey = $this->lockPrefixAdmin;

        // 1. Lấy cache trước
        if ($cached = $this->redis->get(key: $cacheKey)) {
            $semesters = $cached;
        }

        // 2. Cố gắng lock để tránh race condition
        if ($this->redis->lock($lockKey, $this->lockTTL)) {
            try {
                $semesters = $this->semeter->GetSemeter();

                if (!$semesters) {
                    return $this->responseApi(false, ['message' => 'Không có dữ liệu']);
                }

                $this->redis->set($cacheKey, $semesters, $this->cacheTTL);
            } finally {
                $this->redis->unlock($lockKey);
            }
        } else {
            // 3. Nếu lock không được, đợi cache được tạo
            $waited = 0;
            while ($waited < $this->maxWait) {
                usleep($this->step * 1000);
                $waited += $this->step;

                if ($cached = $this->redis->get($cacheKey)) {
                     $semesters = $cached;
                }
            }
        }
        return response()->json(['data' => $semesters], 200);
    }

    public function indexApi(string $codeCampus)
    {
        $data = $this->semeter->GetSemeterAPI($codeCampus);
        return $this->responseApi((bool) $data, $data);
    }

    /**
     * API với Redis cache
     */
    public function indexApiRedis(string $codeCampus)
    {
        $cacheKey = $this->getCacheKey($codeCampus);
        $lockKey = $this->getLockKey($codeCampus);

        if ($cached = $this->redis->get($cacheKey)) {
            return $this->responseApi(true, $cached);
        }

        // Cố gắng lock để tránh race condition
        if ($this->redis->lock($lockKey, $this->lockTTL)) {
            try {
                $data = $this->semeter->GetSemeterAPI($codeCampus);

                if (!$data) {
                    return $this->responseApi(false, ['message' => 'Không có dữ liệu']);
                }

                $this->redis->set($cacheKey, $data, $this->cacheTTL);
            } finally {
                $this->redis->unlock($lockKey);
            }

            return $this->responseApi(true, $data);
        }

        // Đợi cache nếu có tiến trình khác đang tạo
        $waited = 0;
        while ($waited < $this->maxWait) {
            usleep($this->step * 1000);
            $waited += $this->step;

            if ($cached = $this->redis->get($cacheKey)) {
                return $this->responseApi(true, $cached);
            }
        }

        return $this->responseApi(false, ['message' => 'Hệ thống đang bận, vui lòng thử lại']);
    }

    public function edit(int $id)
    {
        try {
            $data = $this->semeter->getItemSemeter($id);
            return response()->json(['message' => 'Thành công', 'data' => $data]);
        } catch (\Throwable) {
            return response()->json(['message' => 'Thất bại'], 404);
        }
    }

    public function create(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'namebasis' => 'required|min:3|unique:semester,name',
                'campus_id' => 'required',
                'status' => 'required',
                'start_time_semeter' => 'nullable|date',
                'end_time_semeter' => 'nullable|date|after:start_time_semeter',
            ],
            [
                'namebasis.unique' => 'Tên kỳ học đã tồn tại',
                'namebasis.required' => 'Không để trống tên kỳ học!',
                'campus_id.required' => 'Vui lòng chọn cơ sở',
                'namebasis.min' => 'Tối thiểu 3 ký tự',
                'status.required' => 'Vui lòng chọn trạng thái',
                'end_time_semeter.after' => 'Thời gian kết thúc phải lớn hơn thời gian bắt đầu',
            ]
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->first()], 422);
        }

        $data = [
            'name' => $request->namebasis,
            'id_campus' => $request->campus_id,
            'status' => $request->status,
            'start_time' => $request->start_time_semeter,
            'end_time' => $request->end_time_semeter,
            'created_at' => now(),
            'updated_at' => null,
        ];

        DB::table('semester')->insert($data);
        $id = DB::getPdo()->lastInsertId();

        $blocks = collect(range(1, 2))->map(fn($i) => [
            'name' => "Block {$i}",
            'id_semeter' => $id,
        ])->toArray();

        DB::table('block_semeter')->insert($blocks);
        
        // reset cache
        $this->redis->reset($this->cachePrefixAdmin);
        $this->redis->reset($this->cachePrefixClient);

        return response()->json([
            'message' => 'Thêm thành công',
            'data' => array_merge($data, ['id' => $id]),
        ], 200);
    }
    public function update(Request $request,$id){
        $validator =  Validator::make(
            $request->all(),
            [
                'namebasis' => 'required|min:3',
                'campus_id_update' => 'required',
                'status' => 'required',
                'start_time_semeter' => 'nullable|date',
                'end_time_semeter' => 'nullable|date|after:start_time_semeter'
            ],
            [
                'namebasis.required' => 'Không để trống tên kỳ học !',
                'campus_id_update.required' => 'Không để trống tên cơ sở !',
                'namebasis.min' => 'Tối thiếu 3 ký tự',
                'status.required' => 'Vui lòng chọn trạng thái',
                'start_time_semeter.nullable' => 'Vui lòng chọn thời gian bắt đầu',
                'end_time_semeter.nullable' => 'Vui lòng chọn thời gian kết thúc',
                'end_time_semeter.after' => 'Thời gian kết thúc phải lớn hơn thời gian bắt đầu',
            ]
        );

        if($validator->fails() == 1){
            $errors = $validator->errors();
            $fields = ['namebasis','campus_id_update','status','start_time_semeter','end_time_semeter'];
            foreach ($fields as $field) {
                $fieldErrors = $errors->get($field);

                if ($fieldErrors) {
                    foreach ($fieldErrors as $error) {
                        return response($error,404);
                    }
                }
            }

        }
        $semeter = $this->semeter->getItemSemeter($id);
        $this->redis->reset($this->cachePrefixClient);
        if (!$semeter) {
            return response()->json(['message' => 'Không tìm thấy'], 404);
        }
        $semeter->name = $request->namebasis;
        $semeter->id_campus = $request->campus_id_update;
        $semeter->status = $request->status;
        $semeter->start_time = $request->start_time_semeter;
        $semeter->end_time = $request->end_time_semeter;
        $semeter->updated_at = $request->end_time;

        $semeter->save();
        $data = $request->all();
        $data['end_time_semeter'] =  $this->formatdate($data['end_time_semeter']);
        $data['start_time_semeter'] =   $this->formatdate($data['start_time_semeter']);
        $data['id'] = $id;

        // reset cache
        $this->redis->reset($this->cachePrefixAdmin);
        $this->redis->reset($this->cachePrefixClient);

        return response( ['message' => "Cập nhật thành công",'data' => $data],200);
    }
    public function now_status(Request $request,$id){
        $campus = $this->semeter->getItemSemeter($id);
        if (!$campus) {
            return response()->json(['message' => 'Không tìm thấy'], 404);
        }
        $campus->status = $request->status;
        $campus->updated_at = now();
        $campus->save();
        $data = $request->all();
        $data['id'] = $id;
        // reset cache
        $this->redis->reset($this->cachePrefixAdmin);
        $this->redis->reset($this->cachePrefixClient);

        return response( ['message' => "Cập nhật trạng thái thành công",'data' =>$data],200);
    }
    public function delete($id)
    {
        try {
            $this->semeter->getItemSemeter($id)->delete();
            // reset cache
            $this->redis->reset($this->cachePrefixAdmin);
            $this->redis->reset($this->cachePrefixClient);

            return response( ['message' => "Xóa Thành công"],200);
        } catch (\Throwable $th) {
            return response( ['message' => "Xóa Thất bại"],404);
        }
    }

    public function formatdate($dateformat){
        $date_start = $dateformat;
        $timestamp = strtotime($date_start);
        return date('d-m-Y', $timestamp);

    }
}

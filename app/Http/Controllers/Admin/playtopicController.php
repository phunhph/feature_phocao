<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExamQuestion;
use App\Models\studentPoetry;
use App\Services\Modules\MStudentManager\PoetryStudent;
use App\Services\Traits\TResponse;
use App\Services\Traits\TUploadImage;
use Exception;
use Illuminate\Http\Request;
use App\Services\Modules\playtopics\playtopic;
use App\Services\Modules\MCampus\Campus;
use App\Services\Modules\MExam\Exam;
use App\Models\Exam as ModelExam;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Log;

class playtopicController extends Controller
{
    use TUploadImage, TResponse;

    public function __construct(
        private playtopic     $playtopicModel,
        private Campus        $Campus,
        private Exam          $exam,
        private PoetryStudent $PoetryStudent,
        private ModelExam     $modelExam
    )
    {
    }

    public function index($id, $id_subject)
    {
        $playtopic = $this->playtopicModel->getList($id);
        $total = count($playtopic) == 0 ? 0 : $playtopic[0]->total;
        $campusList = $this->Campus->getList()->get();
        return view('pages.poetry.playtopic.index', ['playtopics' => $playtopic, 'campusList' => $campusList, 'id_subject' => $id_subject, 'id_poetry' => $id, 'total' => $total]);
    }

    public function show($id)
    {
//        return $id;
//        $round = $this->exam->getItemApi($id);
        $round = $this->playtopicModel->query()
            ->select([
                'playtopic.id',
                'playtopic.exam_name as name',
                'subject.name as name_subject',
            ])
            ->leftJoin('student_poetry', 'student_poetry.id', '=', 'playtopic.student_poetry_id')
            ->leftJoin('poetry', 'poetry.id', '=', 'student_poetry.id_poetry')
            ->leftJoin('subject', 'subject.id', '=', 'poetry.id_subject')
            ->where('playtopic.id', '=', $id)->first();
//        return $round;
        if (is_null($round)) {
            return $this->responseApi(false, 'Không tồn tại trong hệ thống !');
        }
//        {
//            $round->with(['contest' => function ($q) {
//                return $q->with(['rounds' => function ($q) {
//                    $q->orderBy('start_time', 'asc');
//                    $q->setEagerLoads([]);
//                    return $q;
//                }]);
//            }, 'type_exam', 'judges', 'teams' => function ($q) {
//                return $q->with('members');
//            }]);
        return $this->responseApi(
            true, $round);
//            return $this->responseApi(
//                true,
//                $round
//                    ->get()
//                    ->map(function ($col, $key) {
//                        if ($key > 0) return;
//                        $col = $col->toArray();
//                        $user = [];
//                        foreach ($col['judges'] as $judge) {
//                            array_push($user, $judge['user']);
//                        }
//                        $arrResult = array_merge($col, [
//                            'judges' => $user
//                        ]);
//                        return $arrResult;
//                    })[0]
//            );
//        }
    }

    public function indexApi($id_user, $id_poetry, $id_campus, $id_block_subject)
    {
        if (!($data = $this->playtopicModel->getExamApi($id_user, $id_poetry, $id_campus, $id_block_subject))) return $this->responseApi(false);
        return $this->responseApi(true, $data);
    }

    public function listExam($idSubject)
    {
        $data = $this->exam->getListExam($idSubject);

        return response()->json(['data' => $data], 200);

    }

    public function AddTopic(Request $request)
    {
        $rules = ['receive_mode' => 'required', 'time' => 'required|numeric|min:1'];
        if (!isset($request->receive_mode)) {
            $messages = [
                'receive_mode.required' => 'Vui lòng chọn cách thức phát đề!',
                'time.required' => "Vui lòng nhập thời gian thi",
            ];
            $attributes = [];
        } elseif ($request->receive_mode == 0) {
            $rules = array_merge(['exam_id' => 'required',], $rules);
            $messages = [
                'exam_id.required' => "Vui lòng chọn đề thi",
                'time.required' => "Vui lòng nhập thời gian thi",
                'time.numeric' => "Thời gian thi phải là số",
                'time.min' => "Thời gian thi tối thiểu là :min",
            ];
            $attributes = [];
        } elseif ($request->receive_mode == 1) {
            $rules = array_merge($rules, [
                'id_subject' => ['required'],
//                'questions_quantity' => ['required', 'numeric', 'min:1'],
//                'ez_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
//                'me_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
//                'diff_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
            ]);
            $messages = [
                'required' => "Vui lòng nhập :attribute",
                'numeric' => 'Thời gian thi',
                'min' => ":attribute tối thiểu là :min",
                'max' => ":attribute tối đa là :max",
            ];
            $attributes = [
//                'questions_quantity' => 'số lượng câu hỏi',
//                'ez_per_ques' => '% số câu dễ',
//                'me_per_ques' => '% số câu trung bình',
//                'diff_per_ques' => '% số câu khó',
                'time' => 'Thời gian thi',
            ];
        } elseif ($request->receive_mode == 2) {
            $rules = array_merge($rules, [
                'id_subject' => ['required'],
                'questions_quantity' => ['required', 'numeric', 'min:1'],
                'ez_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
                'me_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
                'diff_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
            ]);
            $messages = [
                'required' => "Vui lòng nhập :attribute",
                'numeric' => 'Thời gian thi',
                'min' => ":attribute tối thiểu là :min",
                'max' => ":attribute tối đa là :max",
            ];
            $attributes = [
                'questions_quantity' => 'số lượng câu hỏi',
                'ez_per_ques' => '% số câu dễ',
                'me_per_ques' => '% số câu trung bình',
                'diff_per_ques' => '% số câu khó',
                'time' => 'Thời gian thi',
            ];
        }
        $fields = array_keys($rules);
        $validator = Validator::make($request->all(), $rules, $messages, $attributes);

        if ($validator->fails() == 1) {
            $errors = $validator->errors();
            foreach ($fields as $field) {
                $fieldErrors = $errors->get($field);

                if ($fieldErrors) {
                    foreach ($fieldErrors as $error) {
                        return response($error, 404);
                    }
                }
            }

        }
        $dataInsertArr = [];
        if (!empty($request->poetry_student_id)) {
            $poetriesId = $request->poetry_student_id;
        } else {
            $poetriesId = DB::table('student_poetry')
                ->select(['id'])
                ->where('id_poetry', $request->id_poetry)
                ->pluck('id')->toArray();
        }
//        if ($request->receive_mode == 0) {
//            $questions = DB::table('exam_questions')
//                ->select(['exam_questions.question_id'])
//                ->where('exam_id', $request->exam_id)
//                ->pluck('question_id')->toArray();
//            foreach ($poetriesId as $poetry_id) {
//                shuffle($questions);
//                $dataInsertArr[] = [
//                    'student_poetry_id' => $poetry_id,
//                    'has_received_exam' => 1,
//                    'exam_name' => $request->exam_name,
//                    'questions_order' => json_encode($questions),
//                    'exam_time' => $request->time,
//                ];
//            }
//        } else {
//
//        }
        if ($request->receive_mode == 0) {
            $exam_id = $request->exam_id;
            $exam_name = $request->exam_name;
            $questions = (array)ExamQuestion::query()
                ->select(['question_id'])
                ->where('exam_id', $exam_id)
                ->whereHas('question', function ($q) {
                    $q->where('status', 1)->where('is_current_version', 1);
                })
                ->pluck('question_id')->toArray();
            foreach ($poetriesId as $poetry_id) {
                shuffle($questions);
                $dataInsertArr[] = [
                    'student_poetry_id' => $poetry_id,
                    'has_received_exam' => 1,
                    'exam_name' => $exam_name,
                    'questions_order' => json_encode($questions),
                    'exam_time' => $request->time,
                ];
            }
        } elseif ($request->receive_mode == 1) {
            $examsId = $this->modelExam
//                ->with('questions')
                ->select('id', 'name')
                ->where('subject_id', $request->id_subject)
                ->where('total_questions', ">", 0)
                ->where('status', 1)
                ->get();
            if ($examsId->count() == 0) {
                return response("Không có đề trong ngân hàng đề", 404);
            }
            $exams = [];
            $examsCount = $examsId->count();
            $studentsCount = collect($poetriesId)->count();
            if ($studentsCount < $examsCount) {
                $examsId = $examsId->random($studentsCount);
                $examsCount = $studentsCount;
            }
            $studentPerExam = (int)floor($studentsCount / $examsCount);
            $examsIdArr = $examsId->toArray();
            for ($i = 0; $i < $examsCount; $i++) {
                $exam = (array)$examsIdArr[$i];
                $studentsGet = ($i == $examsCount - 1) ? $studentsCount : $studentPerExam;
                $studentsCount -= $studentsGet;
                $exams[$exam['id']] = [
                    'id' => $exam['id'],
                    'name' => $exam['name'],
                    'total' => $studentsGet,
                ];
            }
            $questionsByExamId = ExamQuestion::query()
                ->select(['question_id', 'id', 'exam_id'])
                ->whereIn('exam_id', $examsId->pluck('id'))
                ->whereHas('question', function ($q) {
                    $q->where('status', 1)->where('is_current_version', 1);
                })
                ->get()
                ->groupBy('exam_id')
                ->map(function ($item) {
                    return $item->pluck('question_id')->toArray();
                });
            shuffle($poetriesId);
            foreach ($poetriesId as $poetry_id) {
                $randomExamId = array_rand($exams, 1);
                $questions = $questionsByExamId[$randomExamId];
                $exam_name = $exams[$randomExamId]['name'];
                if (--$exams[$randomExamId]['total'] <= 0) {
                    unset($exams[$randomExamId]);
                }
                shuffle($questions);
                $dataInsertArr[] = [
                    'student_poetry_id' => $poetry_id,
                    'has_received_exam' => 1,
                    'exam_name' => $exam_name,
                    'questions_order' => json_encode($questions),
                    'exam_time' => $request->time,
                ];
            }
        } elseif ($request->receive_mode == 2) {
            if (($request->diff_per_ques + $request->me_per_ques + $request->ez_per_ques) != 100) {
                return response("Tổng % câu hỏi 3 mức độ phải bằng 100%", 404);
            }
            $questions = ExamQuestion::query()
                ->select(['exam_questions.question_id', 'exam_questions.id', 'questions.rank'])
                ->leftJoin('questions', 'questions.id', '=', 'exam_questions.question_id')
                ->leftJoin('exams', 'exams.id', '=', 'exam_questions.exam_id')
                ->where('exams.subject_id', $request->id_subject)
                ->where('questions.status', 1)
                ->where('questions.is_current_version', 1)
                ->get();

            if ($questions->count() == 0) {
                return response("Không có câu hỏi trong ngân hàng câu hỏi!", 404);
            }

            $questions = $questions
                ->groupBy('rank')
                ->map(function ($item) {
                    return $item->pluck('question_id')->toArray();
                })
                ->toArray();

            $diffQuesNum = round(($request->diff_per_ques / 100) * $request->questions_quantity);
            $meQuesNum = round(($request->me_per_ques / 100) * $request->questions_quantity);
            $ezQuesNum = $request->questions_quantity - $diffQuesNum - $meQuesNum;
            $quesNumArr = [
                config('util.RANK_QUESTION_EASY') => [
                    'rank' => 'dễ',
                    'num' => (int)$ezQuesNum
                ],
                config('util.RANK_QUESTION_MEDIUM') => [
                    'rank' => 'trung bình',
                    'num' => (int)$meQuesNum
                ],
                config('util.RANK_QUESTION_DIFFICULT') => [
                    'rank' => 'khó',
                    'num' => (int)$diffQuesNum
                ],
            ];

            foreach ($quesNumArr as $rank => $ques) {
                if ($ques['num'] <= 0) {
                    continue;
                }

                if ($ques['num'] > count($questions[$rank])) {
                    return response("Số lượng câu hỏi mức độ {$ques['rank']} không đủ, vui lòng điều chỉnh lại", 404);
                }
            }

            foreach ($poetriesId as $poetry_id) {
                $dataInsertArr[] = [
                    'student_poetry_id' => $poetry_id,
                    'has_received_exam' => 1,
                    'exam_name' => "Ngẫu nhiên",
                    'questions_order' => json_encode($this->getRandomQuestionsOrder($quesNumArr, $questions, $request->questions_quantity)),
                    'exam_time' => $request->time,
                ];
            }
        }
        DB::table('playtopic')->whereIn('student_poetry_id', $poetriesId)->delete();
        DB::table('playtopic')->insert($dataInsertArr);
        return response(['message' => "Thành công " . '<br>Vui lòng chờ 5s để làm mới dữ liệu'], 200);
    }

    /**
     * Bản sao của AddTopic nhưng bổ sung lưu cache Redis để
     * client gọi `indexApiRedis` có thể lấy dữ liệu mới ngay lập tức.
     *
     * Các bước:
     * - Thực hiện validation và logic insert DB giống `AddTopic`.
     * - Sau khi insert, cố gắng tạo payload cache bằng cách gọi
     *   method của playtopic model nếu có; nếu không, thử gọi
     *   `getExamApi` như phương án dự phòng để tạo dữ liệu cache.
     * - Lưu payload vào Redis theo pattern key dùng trong
     *   `PoetryController::indexApiRedis` (`poetry_cache:client:{id_poetry}`).
     * - Trả về response thành công giống `AddTopic`.
     */
    public function AddTopicRedis(Request $request)
    {
        // Tái sử dụng logic của AddTopic bằng cách sao chép ở đây.
        $rules = ['receive_mode' => 'required', 'time' => 'required|numeric|min:1'];
        if (!isset($request->receive_mode)) {
            $messages = [
                'receive_mode.required' => 'Vui lòng chọn cách thức phát đề!',
                'time.required' => "Vui lòng nhập thời gian thi",
            ];
            $attributes = [];
        } elseif ($request->receive_mode == 0) {
            $rules = array_merge(['exam_id' => 'required',], $rules);
            $messages = [
                'exam_id.required' => "Vui lòng chọn đề thi",
                'time.required' => "Vui lòng nhập thời gian thi",
                'time.numeric' => "Thời gian thi phải là số",
                'time.min' => "Thời gian thi tối thiểu là :min",
            ];
            $attributes = [];
        } elseif ($request->receive_mode == 1) {
            $rules = array_merge($rules, [
                'id_subject' => ['required'],
            ]);
            $messages = [
                'required' => "Vui lòng nhập :attribute",
                'numeric' => 'Thời gian thi',
                'min' => ":attribute tối thiểu là :min",
                'max' => ":attribute tối đa là :max",
            ];
            $attributes = [
                'time' => 'Thời gian thi',
            ];
        } elseif ($request->receive_mode == 2) {
            $rules = array_merge($rules, [
                'id_subject' => ['required'],
                'questions_quantity' => ['required', 'numeric', 'min:1'],
                'ez_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
                'me_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
                'diff_per_ques' => ['required', 'numeric', 'min:0', 'max: 100'],
            ]);
            $messages = [
                'required' => "Vui lòng nhập :attribute",
                'numeric' => 'Thời gian thi',
                'min' => ":attribute tối thiểu là :min",
                'max' => ":attribute tối đa là :max",
            ];
            $attributes = [
                'questions_quantity' => 'số lượng câu hỏi',
                'ez_per_ques' => '% số câu dễ',
                'me_per_ques' => '% số câu trung bình',
                'diff_per_ques' => '% số câu khó',
                'time' => 'Thời gian thi',
            ];
        }
        $fields = array_keys($rules);
        $validator = Validator::make($request->all(), $rules, $messages, $attributes);

        if ($validator->fails() == 1) {
            $errors = $validator->errors();
            foreach ($fields as $field) {
                $fieldErrors = $errors->get($field);

                if ($fieldErrors) {
                    foreach ($fieldErrors as $error) {
                        return response($error, 404);
                    }
                }
            }

        }
        $dataInsertArr = [];
        if (!empty($request->poetry_student_id)) {
            $poetriesId = $request->poetry_student_id;
        } else {
            $poetriesId = DB::table('student_poetry')
                ->select(['id'])
                ->where('id_poetry', $request->id_poetry)
                ->pluck('id')->toArray();
        }

        if ($request->receive_mode == 0) {
            $exam_id = $request->exam_id;
            $exam_name = $request->exam_name;
            $questions = (array)ExamQuestion::query()
                ->select(['question_id'])
                ->where('exam_id', $exam_id)
                ->whereHas('question', function ($q) {
                    $q->where('status', 1)->where('is_current_version', 1);
                })
                ->pluck('question_id')->toArray();
            foreach ($poetriesId as $poetry_id) {
                shuffle($questions);
                $dataInsertArr[] = [
                    'student_poetry_id' => $poetry_id,
                    'has_received_exam' => 1,
                    'exam_name' => $exam_name,
                    'questions_order' => json_encode($questions),
                    'exam_time' => $request->time,
                ];
            }
        } elseif ($request->receive_mode == 1) {
            $examsId = $this->modelExam
                ->select('id', 'name')
                ->where('subject_id', $request->id_subject)
                ->where('total_questions', ">", 0)
                ->where('status', 1)
                ->get();
            if ($examsId->count() == 0) {
                return response("Không có đề trong ngân hàng đề", 404);
            }
            $exams = [];
            $examsCount = $examsId->count();
            $studentsCount = collect($poetriesId)->count();
            if ($studentsCount < $examsCount) {
                $examsId = $examsId->random($studentsCount);
                $examsCount = $studentsCount;
            }
            $studentPerExam = (int)floor($studentsCount / $examsCount);
            $examsIdArr = $examsId->toArray();
            for ($i = 0; $i < $examsCount; $i++) {
                $exam = (array)$examsIdArr[$i];
                $studentsGet = ($i == $examsCount - 1) ? $studentsCount : $studentPerExam;
                $studentsCount -= $studentsGet;
                $exams[$exam['id']] = [
                    'id' => $exam['id'],
                    'name' => $exam['name'],
                    'total' => $studentsGet,
                ];
            }
            $questionsByExamId = ExamQuestion::query()
                ->select(['question_id', 'id', 'exam_id'])
                ->whereIn('exam_id', $examsId->pluck('id'))
                ->whereHas('question', function ($q) {
                    $q->where('status', 1)->where('is_current_version', 1);
                })
                ->get()
                ->groupBy('exam_id')
                ->map(function ($item) {
                    return $item->pluck('question_id')->toArray();
                });
            shuffle($poetriesId);
            foreach ($poetriesId as $poetry_id) {
                $randomExamId = array_rand($exams, 1);
                $questions = $questionsByExamId[$randomExamId];
                $exam_name = $exams[$randomExamId]['name'];
                if (--$exams[$randomExamId]['total'] <= 0) {
                    unset($exams[$randomExamId]);
                }
                shuffle($questions);
                $dataInsertArr[] = [
                    'student_poetry_id' => $poetry_id,
                    'has_received_exam' => 1,
                    'exam_name' => $exam_name,
                    'questions_order' => json_encode($questions),
                    'exam_time' => $request->time,
                ];
            }
        } elseif ($request->receive_mode == 2) {
            if (($request->diff_per_ques + $request->me_per_ques + $request->ez_per_ques) != 100) {
                return response("Tổng % câu hỏi 3 mức độ phải bằng 100%", 404);
            }
            $questions = ExamQuestion::query()
                ->select(['exam_questions.question_id', 'exam_questions.id', 'questions.rank'])
                ->leftJoin('questions', 'questions.id', '=', 'exam_questions.question_id')
                ->leftJoin('exams', 'exams.id', '=', 'exam_questions.exam_id')
                ->where('exams.subject_id', $request->id_subject)
                ->where('questions.status', 1)
                ->where('questions.is_current_version', 1)
                ->get();

            if ($questions->count() == 0) {
                return response("Không có câu hỏi trong ngân hàng câu hỏi!", 404);
            }

            $questions = $questions
                ->groupBy('rank')
                ->map(function ($item) {
                    return $item->pluck('question_id')->toArray();
                })
                ->toArray();

            $diffQuesNum = round(($request->diff_per_ques / 100) * $request->questions_quantity);
            $meQuesNum = round(($request->me_per_ques / 100) * $request->questions_quantity);
            $ezQuesNum = $request->questions_quantity - $diffQuesNum - $meQuesNum;
            $quesNumArr = [
                config('util.RANK_QUESTION_EASY') => [
                    'rank' => 'dễ',
                    'num' => (int)$ezQuesNum
                ],
                config('util.RANK_QUESTION_MEDIUM') => [
                    'rank' => 'trung bình',
                    'num' => (int)$meQuesNum
                ],
                config('util.RANK_QUESTION_DIFFICULT') => [
                    'rank' => 'khó',
                    'num' => (int)$diffQuesNum
                ],
            ];

            foreach ($quesNumArr as $rank => $ques) {
                if ($ques['num'] <= 0) {
                    continue;
                }

                if ($ques['num'] > count($questions[$rank])) {
                    return response("Số lượng câu hỏi mức độ {$ques['rank']} không đủ, vui lòng điều chỉnh lại", 404);
                }
            }

            foreach ($poetriesId as $poetry_id) {
                $dataInsertArr[] = [
                    'student_poetry_id' => $poetry_id,
                    'has_received_exam' => 1,
                    'exam_name' => "Ngẫu nhiên",
                    'questions_order' => json_encode($this->getRandomQuestionsOrder($quesNumArr, $questions, $request->questions_quantity)),
                    'exam_time' => $request->time,
                ];
            }
        }

        // Persist changes to DB (same as AddTopic)
        DB::table('playtopic')->whereIn('student_poetry_id', $poetriesId)->delete();
        DB::table('playtopic')->insert($dataInsertArr);

        // --- Caching Redis: cố gắng cập nhật cache poetry để indexApiRedis trả dữ liệu mới.
        // Dùng cùng pattern key như `PoetryController::indexApiRedis`

        try {
            // Lấy danh sách student_id từ bảng student_poetry dựa trên student_poetry_id
            $studentPoetryData = DB::table('student_poetry')
                ->whereIn('id', $poetriesId)
                ->select(['id', 'id_student'])
                ->get();

            // Tạo map nhanh từ student_poetry_id => id_student
            $mapStudentPoetryToStudentId = [];
            foreach ($studentPoetryData as $record) {
                $mapStudentPoetryToStudentId[$record->id] = $record->id_student;
            }

            // Lưu cache cho mỗi student (playtopic ngắn) và payload câu hỏi đầy đủ
            foreach ($dataInsertArr as $entry) {
                $studentPoetryId = $entry['student_poetry_id'] ?? null;
                if (!$studentPoetryId || !isset($mapStudentPoetryToStudentId[$studentPoetryId])) continue;

                $studentId = $mapStudentPoetryToStudentId[$studentPoetryId];
                $cacheKeyPerStudent = "poetry_cache:client:{$request->id_poetry}:{$studentId}";

                // Xóa cache cũ trên Redis
                Cache::forget($cacheKeyPerStudent);

                // Lưu cache mới vào Redis với TTL 24 giờ (dữ liệu playtopic dạng rút gọn)
                Cache::put($cacheKeyPerStudent, $entry, 86400);

                // --- Bổ sung: lưu payload câu hỏi đầy đủ (câu hỏi + đáp án + ảnh nếu có)
                // Key: cache_question:{poetryId}:{studentId}
                try {
                    $questionsOrder = json_decode($entry['questions_order'] ?? '[]', true);
                    if (!empty($questionsOrder) && is_array($questionsOrder)) {
                       
                        $questions = \App\Models\Question::with(['answers', 'imagesDriver'])
                            ->whereIn('id', $questionsOrder)
                            ->get()
                            ->keyBy('id');

                        // Sắp xếp lại theo thứ tự ban đầu
                        $orderedQuestions = [];
                        foreach ($questionsOrder as $qid) {
                            if (isset($questions[$qid])) {
                                $q = $questions[$qid]->toArray();
                                $orderedQuestions[] = $q;
                            }
                        }

                        $cacheQuestionsKey = "cache_question:{$request->id_poetry}:{$studentId}";
                        // Xóa cache cũ trên Redis
                        Cache::forget($cacheQuestionsKey);
                        Cache::put($cacheQuestionsKey, $orderedQuestions);
                    }
                } catch (\Throwable $e) {
                    Log::error("Lỗi khi lưu cache câu hỏi đầy đủ cho poetry_id {$request->id_poetry}, student_id {$studentId}: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Bỏ qua lỗi cache để tránh làm hỏng luồng chính.
        }

        return response(['message' => "Thành công " . '<br>Vui lòng chờ 5s để làm mới dữ liệu'], 200);
    }

    public function getRandomQuestionsOrder($quesNumArr, $questions, $questions_quantity)
    {
        $questions = (array)$questions;
        try {
            $questionsOrder = [];
            foreach ($quesNumArr as $rank => $quesNum) {
                $num = $quesNum['num'];
                if ($num <= 0) {
                    continue;
                }
                $randomKeys = array_rand($questions[$rank], $quesNum['num']);
                $randomElements = array_intersect_key($questions[$rank], array_flip((array)$randomKeys));
                array_push($questionsOrder, ...$randomElements);
            }
            $finalQuestionsOrder = $questionsOrder;
            $numQuesRandom = count($questionsOrder);
            if ((int)$questions_quantity < $numQuesRandom) {
                $keyRemoveRandom = array_rand($questionsOrder, $numQuesRandom - (int)$questions_quantity);
                $keyKeeping = array_keys(array_diff_key($questionsOrder, array_flip((array)$keyRemoveRandom)));
                $finalQuestionsOrder = array_intersect_key($questionsOrder, array_flip((array)$keyKeeping));
            }
            shuffle($finalQuestionsOrder);
            return $finalQuestionsOrder;
        } catch (Exception $e) {
            return response("Có lỗi xảy ra khi trộn đề, vui lòng nhập giá trị khác");
        }
    }

    public function AddTopicReload(Request $request)
    {

        $validator = Validator::make(
            $request->all(),
            [
                'campuses_id' => 'required',
                'receive_mode' => 'required',
                'id_subject' => 'required',
                'exam_id' => 'required'
            ],
            [
                'receive_mode.required' => 'Vui lòng chọn chế độ trộn câu hỏi!',
                'campuses_id.required' => 'Vui lòng chọn cơ sở!',
                'exam_id.required' => 'Vui lòng chọn đề!',
                'id_subject.required' => 'Không có data môn học'
            ]
        );

        if ($validator->fails() == 1) {
            $errors = $validator->errors();
            $fields = ['receive_mode', 'campuses_id', 'id_subject', 'exam_id'];
            foreach ($fields as $field) {
                $fieldErrors = $errors->get($field);

                if ($fieldErrors) {
                    foreach ($fieldErrors as $error) {
                        return response($error, 404);
                    }
                }
            }

        }

        if (!($liststudent = $this->PoetryStudent->GetStudents($request->id_poetry))) return abort(404);

        if (count($liststudent) == 0) {
            return response('Không có sinh viên trong ca thi này', 404);
        }

        $playtopic = $this->playtopicModel->getList($request->id_poetry);
        foreach ($playtopic as $value) {
            $value->delete();
        }
//        DB::table('playtopic')->where('id_poetry', $request->id_poetry)->delete();

        $is_receive_mode = $request->receive_mode == 1;
        $questions = ExamQuestion::query()
            ->select('question_id')
            ->where('exam_id', $request->exam_id)
            ->whereHas('question', function ($q) {
                $q->where('status', 1)->where('is_current_version', 1);
            })
            ->pluck('question_id')->toArray();
        $dataInsertArr = [];
        foreach ($liststudent as $object) {
            if ($is_receive_mode) shuffle($questions);
            $dataInsertArr[] = [
                'id_user' => $object->id_student,
                'id_exam' => $request->exam_id,
                'id_poetry' => $request->id_poetry,
                'id_campus' => $request->campuses_id,
                'id_subject' => $request->id_subject,
                'total' => 1,
                'questions_order' => json_encode($questions),
                'created_at' => now(),
                'updated_at' => null
            ];
//            $dataInsertArr[] = $dataInsert;
//            DB::table('playtopic')->insert($dataInsert);
        }
        DB::table('playtopic')->insert($dataInsertArr);
        return response(['message' => "Thành công " . '<br>Vui lòng chờ 5s để làm mới dữ liệu'], 200);
    }
}

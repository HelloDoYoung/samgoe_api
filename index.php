<?php
include 'config.php';

header('Content-Type: application/json; charset=UTF-8');

// CORS 허용 헤더 추가
header('Access-Control-Allow-Origin: *'); // 모든 도메인에서 접근을 허용합니다.
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); // 허용할 HTTP 메서드를 지정합니다.
header('Access-Control-Allow-Headers: Content-Type'); // 허용할 HTTP 헤더를 지정합니다.

$apiKey = api_key;
$type = isset($_GET['type']) ? $_GET['type'] : '';
$date = isset($_GET['date']) ? $_GET['date'] : date('Ymd');
$grade = isset($_GET['grade']) ? $_GET['grade'] : '';
$class_nm = isset($_GET['class']) ? $_GET['class'] : '';

$lunchApiUrl = 'https://open.neis.go.kr/hub/mealServiceDietInfo';
$scheduleApiUrl = 'https://open.neis.go.kr/hub/SchoolSchedule';
$timetableApiUrl = 'https://open.neis.go.kr/hub/hisTimetable';
$parsingUrl = 'https://samgoe-h.goehs.kr/samgoe-h/ad/fm/foodmenu/selectFoodMenuView.do';

$atptOfcdcScCode = 'J10';
$sdSchulCode = '7530660';
$dayOfWeek = date('w', strtotime($date));

$allergyMap = [
    1 => '난류',
    2 => '우유',
    3 => '메밀',
    4 => '땅콩',
    5 => '대두',
    6 => '밀',
    7 => '고등어',
    8 => '게',
    9 => '새우',
    10 => '돼지고기',
    11 => '복숭아',
    12 => '토마토',
    13 => '아황산류',
    14 => '호두',
    15 => '닭고기',
    16 => '쇠고기',
    17 => '오징어',
    18 => '조개류(굴,전복,홍합 포함)',
    19 => '잣'
];

if($type === 'breakfast' || $type === 'lunch' || $type === 'dinner' || $type === 'timetable' || $type === 'schedule') {
    function cleanMenuItem($item) {
        return trim(preg_replace('/[\d\(\)\*\.]/', '', $item));
    }

    function extractAllergies($item, $allergyMap) {
        preg_match_all('/\d+/', $item, $matches);
        $allergies = array_unique(array_map(function($num) use ($allergyMap) {
            return $allergyMap[$num] ?? null;
        }, $matches[0]));
        return [
            'allergies' => array_filter($allergies),
            'allergies_num' => $matches[0]
        ];
    }

    function getAllergyInfo($allergyMap) {
        return $allergyMap;
    }

    function parseNutritionalInfo($ntrInfo) {
        $lines = explode('<br/>', $ntrInfo);
        $parsed = [];
        foreach ($lines as $index => $line) {
            $parts = explode(' : ', $line);
            if (count($parts) == 2) {
                $parsed['ntr_' . ($index + 1)] = [
                    'name' => $parts[0],
                    'value' => floatval($parts[1])
                ];
            }
        }
        $parsed['totalntr'] = count($lines);
        return $parsed;
    }

    if ($type === '') {
        $code_number = "INFO-400";
        $info_message = "올바르지 않은 요청입니다.";
        $responseData = [
            'status' => [
                'code' => $code_number,
                'message' => $info_message,
            ]
        ];
        echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
    } elseif ($type === 'schedule') {
        $apiUrl = sprintf('%s?Key=%s&Type=json&ATPT_OFCDC_SC_CODE=%s&SD_SCHUL_CODE=%s&AA_YMD=%s',
            $scheduleApiUrl, $apiKey, $atptOfcdcScCode, $sdSchulCode, $date);
    } elseif ($type === 'lunch') {
        $apiUrl = sprintf('%s?Key=%s&Type=json&ATPT_OFCDC_SC_CODE=%s&SD_SCHUL_CODE=%s&MLSV_YMD=%s',
            $lunchApiUrl, $apiKey, $atptOfcdcScCode, $sdSchulCode, $date);
    } elseif ($type === 'timetable') {
        if ($grade === '' || $class_nm === '') {
            $code_number = "INFO-300";
            $info_message = "요청인자가 누락되었습니다.";
            $responseData = [
                'status' => [
                    'code' => $code_number,
                    'message' => $info_message,
                ]
            ];
            echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $apiUrl = sprintf('%s?Key=%s&Type=json&ATPT_OFCDC_SC_CODE=%s&SD_SCHUL_CODE=%s&SEM=1&ALL_TI_YMD=%s&GRADE=%s&CLASS_NM=%s',
            $timetableApiUrl, $apiKey, $atptOfcdcScCode, $sdSchulCode, $date, $grade, $class_nm);
    }

    if ($type === 'breakfast' || $type === 'dinner') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $parsingUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $html = curl_exec($ch);
        curl_close($ch);

        $dom = new DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        if ($type === 'breakfast') {
            $isBreakfast = $xpath->query('//*[@id="detailForm"]/div/table/tbody/tr[1]/th')->item(0)->nodeValue == '조식';
            if ($isBreakfast) {
                $menuNodes = $xpath->query('//*[@id="detailForm"]/div/table/tbody/tr[1]/td[' . ($dayOfWeek + 1) . ']/div/p[2]/text()');
                $breakfastData = [];
                $allergyData = [];
                foreach ($menuNodes as $index => $menuNode) {
                    $menuItem = trim($menuNode->nodeValue);
                    $cleanedMenuItem = cleanMenuItem($menuItem);
                    $allergyDetails = extractAllergies($menuItem, $allergyMap);

                    $breakfastData['breakfast' . ($index + 1)] = $cleanedMenuItem;
                    if (empty($allergyDetails['allergies'])) {
                        $allergyData['allergy' . ($index + 1)] = "null";
                    } else {
                        $allergyData['allergy' . ($index + 1)] = [
                            'totalallergy' => count($allergyDetails['allergies']),
                            'allergies' => $allergyDetails['allergies'],
                            'allergies_num' => $allergyDetails['allergies_num']
                        ];
                    }
                }
                $breakfastData['totalmenu'] = count($menuNodes);
                if (count($menuNodes) > 1) {
                    $code_number = "INFO-000";
                    $info_message = "정상 처리되었습니다.";
                    $date_year = substr($date, 0, 4);
                    $date_month = substr($date, 4, 2);
                    $date_day = substr($date, 6, 2);
                    $date_response = [
                        'date' => $date,
                        'date_year' => $date_year,
                        'date_month' => $date_month,
                        'date_day' => $date_day
                    ];
                    $status = [
                        'code' => $code_number,
                        'message' => $info_message
                    ];
                    $responseData = [
                        'menu' => $breakfastData,
                        'allergy' => $allergyData,
                        'allergy_info' => getAllergyInfo($allergyMap),
                        'date' => $date_response,
                        'status' => $status,
                    ];
                } else {
                    $code_number = "INFO-200";
                    $info_message = "해당하는 데이터가 없습니다.";
                    $resData = [
                        'code' => $code_number,
                        'message' => $info_message
                    ];
                    $responseData = ['status' => $resData];
                }
                echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
            } else {
                $code_number = "INFO-200";
                $info_message = "해당하는 데이터가 없습니다.";
                $resData = [
                    'code' => $code_number,
                    'message' => $info_message
                ];
                $responseData = ['status' => $resData];
                echo json_encode($responseData, JSON_UNESCAPED_UNICODE);            }
        } elseif ($type === 'dinner') {
            $isDinner = $xpath->query('//*[@id="detailForm"]/div/table/tbody/tr[3]/th')->item(0)->nodeValue == '석식';
            if ($isDinner) {
                $menuNodes = $xpath->query('//*[@id="detailForm"]/div/table/tbody/tr[3]/td[' . ($dayOfWeek + 1) . ']/div/p[2]/text()');
                $dinnerData = [];
                $allergyData = [];
                foreach ($menuNodes as $index => $menuNode) {
                    $menuItem = trim($menuNode->nodeValue);
                    $cleanedMenuItem = cleanMenuItem($menuItem);
                    $allergyDetails = extractAllergies($menuItem, $allergyMap);

                    $dinnerData['dinner' . ($index + 1)] = $cleanedMenuItem;
                    if (empty($allergyDetails['allergies'])) {
                        $allergyData['allergy' . ($index + 1)] = "null";
                    } else {
                        $allergyData['allergy' . ($index + 1)] = [
                            'totalallergy' => count($allergyDetails['allergies']),
                            'allergies' => $allergyDetails['allergies'],
                            'allergies_num' => $allergyDetails['allergies_num']
                        ];
                    }
                }
                $dinnerData['totalmenu'] = count($menuNodes);
                if (count($menuNodes) > 1) {
                    $code_number = "INFO-000";
                    $info_message = "정상 처리되었습니다.";
                    $date_year = substr($date, 0, 4);
                    $date_month = substr($date, 4, 2);
                    $date_day = substr($date, 6, 2);
                    $date_response = [
                        'date' => $date,
                        'date_year' => $date_year,
                        'date_month' => $date_month,
                        'date_day' => $date_day
                    ];
                    $status = [
                        'code' => $code_number,
                        'message' => $info_message
                    ];
                    $responseData = [
                        'menu' => $dinnerData,
                        'allergy' => $allergyData,
                        'allergy_info' => getAllergyInfo($allergyMap),
                        'date' => $date_response,
                        'status' => $status,                    
                    ];
                } else {
                    $code_number = "INFO-200";
                    $info_message = "해당하는 데이터가 없습니다.";
                    $resData = [
                        'code' => $code_number,
                        'message' => $info_message
                    ];
                    $responseData = ['status' => $resData];
                }
                echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
            } else {
                $code_number = "INFO-200";
                $info_message = "해당하는 데이터가 없습니다.";
                $resData = [
                    'code' => $code_number,
                    'message' => $info_message
                ];
                $responseData = ['status' => $resData];
                echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
            }
        }
    } else {
        $response = file_get_contents($apiUrl);
    }

    if ($type !== 'breakfast' && $type !== 'dinner' && $type !== '') {
        $data = json_decode($response, true);
    }

    if ($type === 'schedule') {
        if (isset($data['SchoolSchedule'][1]['row'])) {
            $events = $data['SchoolSchedule'][1]['row'];
            $eventData = [];
            foreach ($events as $index => $event) {
                $eventData['event' . ($index + 1)] = $event['EVENT_NM'];
            }
            $eventData['totalevent'] = count($events);
            $resultCode = $data['SchoolSchedule'][0]['head'][1]['RESULT']['CODE'] ?? null;
            $resultMessage = $data['SchoolSchedule'][0]['head'][1]['RESULT']['MESSAGE'] ?? null;
            $date_year = substr($date, 0, 4);
            $date_month = substr($date, 4, 2);
            $date_day = substr($date, 6, 2);
            $date_response = [
                'date' => $date,
                'date_year' => $date_year,
                'date_month' => $date_month,
                'date_day' => $date_day
            ];
            $status = [
                'code' => $resultCode,
                'message' => $resultMessage
            ];
            $responseData = [
                'data' => $eventData,
                'date' => $date_response,
                'status' => $status,
            ];
            echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
        } else {
            $code_number = "INFO-200";
            $info_message = "해당하는 데이터가 없습니다.";
            $resData = [
                'code' => $code_number,
                'message' => $info_message
            ];
            $responseData = ['status' => $resData];
            echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
        }
    } elseif ($type === 'lunch') {
        if (isset($data['mealServiceDietInfo'][1]['row'][0]['DDISH_NM'])) {
            $dishString = $data['mealServiceDietInfo'][1]['row'][0]['DDISH_NM'];
            $dishString = str_replace('<br/>', "\n", $dishString);
            $dishes = preg_split('/\r\n|[\r\n]/', $dishString);
            $mealData = [];
            $allergyData = [];
            foreach ($dishes as $index => $dish) {
                $menuItem = trim($dish);
                $menuItem = rtrim($menuItem); // Remove trailing whitespaces
                $cleanedMenuItem = cleanMenuItem($menuItem);
                $allergyDetails = extractAllergies($menuItem, $allergyMap);

                $mealData['meal' . ($index + 1)] = $cleanedMenuItem;
                if (empty($allergyDetails['allergies'])) {
                    $allergyData['allergy' . ($index + 1)] = "null";
                } else {
                    $allergyData['allergy' . ($index + 1)] = [
                        'totalallergy' => count($allergyDetails['allergies']),
                        'allergies' => $allergyDetails['allergies'],
                        'allergies_num' => $allergyDetails['allergies_num']
                    ];
                }
            }
            $mealData['totalmenu'] = count($dishes);

            $calInfo = $data['mealServiceDietInfo'][1]['row'][0]['CAL_INFO'];
            $ntrInfo = $data['mealServiceDietInfo'][1]['row'][0]['NTR_INFO'];
            $parsedNtrInfo = parseNutritionalInfo($ntrInfo);

            $resultCode = $data['mealServiceDietInfo'][0]['head'][1]['RESULT']['CODE'] ?? null;
            $resultMessage = $data['mealServiceDietInfo'][0]['head'][1]['RESULT']['MESSAGE'] ?? null;
            $date_year = substr($date, 0, 4);
            $date_month = substr($date, 4, 2);
            $date_day = substr($date, 6, 2);
            $date_response = [
                'date' => $date,
                'date_year' => $date_year,
                'date_month' => $date_month,
                'date_day' => $date_day
            ];
            $status = [
                'code' => $resultCode,
                'message' => $resultMessage
            ];
            $responseData = [
                'menu' => $mealData,
                'allergy' => $allergyData,
                'allergy_info' => getAllergyInfo($allergyMap),
                'cal_info' => $calInfo,
                'ntr_info' => $parsedNtrInfo,
                'date' => $date_response,
                'status' => $status,
            ];
            echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
        } else {
            $code_number = "INFO-200";
            $info_message = "해당하는 데이터가 없습니다.";
            $resData = [
                'code' => $code_number,
                'message' => $info_message
            ];
            $responseData = ['status' => $resData];
            echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
        }
    } elseif ($type === 'timetable') {
        if (isset($data['hisTimetable'][1]['row'])) {
            $timetable = $data['hisTimetable'][1]['row'];
            $timetableData = [];
            foreach ($timetable as $index => $class) {
                // Initialize variables with default values to avoid undefined index notices
                $period = isset($class['PERIO']) ? $class['PERIO'] : null;
                $subject = isset($class['ITRT_CNTNT']) ? $class['ITRT_CNTNT'] : null;

                // Build timetable class data
                $timetableData['class' . ($index + 1)] = [
                    'period' => $period,
                    'subject' => $subject,
                    // Include additional fields here if needed
                ];
            }
            $timetableData['totalclasses'] = count($timetable);
            $resultCode = isset($data['hisTimetable'][0]['head'][1]['RESULT']['CODE']) ? $data['hisTimetable'][0]['head'][1]['RESULT']['CODE'] : null;
            $resultMessage = isset($data['hisTimetable'][0]['head'][1]['RESULT']['MESSAGE']) ? $data['hisTimetable'][0]['head'][1]['RESULT']['MESSAGE'] : null;
            $date_year = substr($date, 0, 4);
            $date_month = substr($date, 4, 2);
            $date_day = substr($date, 6, 2);
            $date_response = [
                'date' => $date,
                'date_year' => $date_year,
                'date_month' => $date_month,
                'date_day' => $date_day
            ];
            $class_info = [
                'grade' => $grade,
                'class_nm' => $class_nm
            ];
            $status = [
                'code' => $resultCode,
                'message' => $resultMessage
            ];
            $responseData = [
                'data' => $timetableData,
                'class_info' => $class_info,
                'date' => $date_response,
                'status' => $status,
            ];
            echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
        } else {
            $code_number = "INFO-200";
            $info_message = "해당하는 데이터가 없습니다.";
            $responseData = [
                'status' => [
                    'code' => $code_number,
                    'message' => $info_message,
                ]
            ];
            echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
        }
    }
} else {
    $code_number = "INFO-400";
    $info_message = "올바르지 않은 요청입니다.";
    $responseData = [
        'status' => [
            'code' => $code_number,
            'message' => $info_message,
        ]
    ];
    echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
}
?>

<?php
/**
 * Ando EPG API 接口
 * 调用方式: epg_api.php?ch=频道名
 */

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // 允许跨域

$ch = isset($_GET['ch']) ? trim($_GET['ch']) : '';

if (empty($ch)) {
    http_response_code(400);
    die(json_encode(['error' => '请提供频道名称参数 ch'], JSON_UNESCAPED_UNICODE));
}

// 搜索顺序：国内 -> 香港 -> 台湾
$dirs = ['CN', 'HK', 'TW'];
$found = false;

foreach ($dirs as $dir) {
    // 拼接绝对路径，确保读取准确
    $filePath = __DIR__ . "/{$dir}/{$ch}.json";
    
    if (file_exists($filePath)) {
        // 找到文件，直接读取并输出内容
        $content = file_get_contents($filePath);
        if ($content) {
            echo $content;
            $found = true;
            break; 
        }
    }
}

// 如果没找到，尝试进行一次“模糊搜索”（防止频道名带了空格或微小差异）
if (!$found) {
    foreach ($dirs as $dir) {
        // 搜索包含关键词的 json 文件
        $files = glob(__DIR__ . "/{$dir}/*{$ch}*.json");
        if (!empty($files)) {
            echo file_get_contents($files[0]);
            $found = true;
            break;
        }
    }
}

// 彻底没找到
if (!$found) {
    http_response_code(404);
    echo json_encode([
        'error'   => '未找到该频道的节目单',
        'channel' => $ch,
        'msg'     => '请检查频道名称是否与生成的 JSON 文件名一致'
    ], JSON_UNESCAPED_UNICODE);
}

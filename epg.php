<?php
/**
 * Ando EPG 分类处理器
 * 功能：解析 XML/JSON EPG 数据，按原始名称分箱存储为 JSON
 */

$baseDir = __DIR__ . '/EPG/'; 
ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

// 待处理的文件列表
$xmlFilesToProcess = ['pl.xml', 'hk.xml', 'tw.xml'];

$globalFileCount = 0;
$filesPerFolder = 900; // 每文件夹存放 900 个文件

echo "🚀 EPG 处理器启动...\n";

$channels = [];
$channelNames = [];

foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $baseDir . $fileName;
    if (!file_exists($filePath)) {
        echo "⚠️ 跳过不存在的文件：$fileName\n";
        continue;
    }

    echo "📖 正在解析：$fileName\n";
    $content = file_get_contents($filePath);
    if (empty($content)) continue;

    $firstChar = substr(trim($content), 0, 1);

    // --- 逻辑 A: 处理 JSON 格式 ---
    if ($firstChar === '{' || $firstChar === '[') {
        echo "💡 检测到 JSON 数据流...\n";
        $data = json_decode($content, true);
        if ($data) {
            $items = $data['epg_data'] ?? (is_array($data) ? $data : []);
            $chId = $data['channel_id'] ?? $fileName;
            // 直接读取原始名称，不做转换
            $chName = $data['channel_name'] ?? $chId;
            $channelNames[$chId] = $chName;

            foreach ($items as $item) {
                $rawStart = $item['start_time'] ?? ($item['start'] ?? '');
                $rawEnd = $item['end_time'] ?? ($item['end'] ?? '');
                
                $channels[$chId][] = [
                    'start'     => substr(str_replace([':', ' '], '', $rawStart), 8, 2) . ':' . substr(str_replace([':', ' '], '', $rawStart), 10, 2),
                    'startTime' => str_pad(preg_replace('/\D/', '', $rawStart), 14, '0', STR_PAD_RIGHT),
                    'stopTime'  => str_pad(preg_replace('/\D/', '', $rawEnd), 14, '0', STR_PAD_RIGHT),
                    'program'   => $item['title'] ?? ($item['program'] ?? '精彩节目')
                ];
            }
        }
    } 
    // --- 逻辑 B: 处理标准 XML 格式 ---
    else {
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
        if (!$xml) {
            echo "❌ 无法解析 XML 格式: $fileName\n";
            continue;
        }

        if (isset($xml->channel)) {
            foreach ($xml->channel as $ch) {
                $id = trim((string)$ch['id']);
                // 保持原始简繁体和大小写
                $name = trim((string)$ch->{'display-name'});
                if ($id && $name) $channelNames[$id] = $name;
            }
        }

        if (isset($xml->programme)) {
            foreach ($xml->programme as $prog) {
                $chId = trim((string)$prog['channel']);
                $start = (string)$prog['start'];
                $stop = (string)$prog['stop'];
                $channels[$chId][] = [
                    'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
                    'startTime' => substr($start, 0, 14),
                    'stopTime'  => substr($stop, 0, 14),
                    'program'   => trim((string)$prog->title)
                ];
            }
        }
    }
    unset($content, $xml, $data);
}

// --- 写入 JSON 逻辑 ---
echo "⚙️ 正在执行分箱逻辑并写入子目录...\n";

foreach ($channels as $id => $progList) {
    // 优先使用 display-name，没有则用 ID
    $displayName = $channelNames[$id] ?? $id;
    if (empty($displayName)) continue;

    // 排序与去重
    usort($progList, function($a, $b) {
        return strcmp($a['startTime'], $b['startTime']);
    });
    $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));
    $jsonEncoded = json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // 原始名称
    $targetName = trim($displayName);
    
    // 计算文件夹索引 (01, 02...)
    $folderIdx = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
    $targetDir = $baseDir . $folderIdx . '/';

    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    // 过滤掉系统文件名非法字符，但保持文字原始内容
    $safeFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $targetName);
    
    if (file_put_contents($targetDir . $safeFileName . '.json', $jsonEncoded)) {
        $globalFileCount++;
    }
}

echo "\n✨ 任务圆满完成！";
echo "\n📊 总计生成文件: $globalFileCount 个";
$lastFolder = str_pad(ceil($globalFileCount / $filesPerFolder), 2, '0', STR_PAD_LEFT);
echo "\n📂 存储路径: EPG/01 到 EPG/$lastFolder\n";

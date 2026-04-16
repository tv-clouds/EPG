<?php
/**
 * Ando EPG 分类处理器 - 2026 全格式兼容版
 * 兼容标准 XML 格式与 loc.cc 的 JSON-in-XML 格式
 */

$baseDir = __DIR__ . '/EPG/'; 
ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

// 工作流中下载的 4 个文件名
$xmlFilesToProcess = ['cn.xml', 'hk.xml', 'tw.xml', 'all.xml'];

$globalFileCount = 0;
$filesPerFolder = 900;

echo "🚀 EPG 处理器启动...\n";

$channels = [];
$channelNames = [];

foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $baseDir . $fileName;
    if (!file_exists($filePath)) continue;

    echo "📖 正在解析：$fileName\n";
    $content = file_get_contents($filePath);
    if (empty($content)) continue;

    $firstChar = substr(trim($content), 0, 1);

    // --- 逻辑 A: 处理 JSON 格式 (针对 loc.cc 等源) ---
    if ($firstChar === '{' || $firstChar === '[') {
        echo "💡 检测到 JSON 数据流，执行动态转换...\n";
        $data = json_decode($content, true);
        
        if ($data) {
            // loc.cc 的结构通常在 epg_data 键下，或者直接是数组
            $items = $data['epg_data'] ?? (is_array($data) ? $data : []);
            $chId = $data['channel_id'] ?? $fileName;
            $chName = $data['channel_name'] ?? $chId;
            $channelNames[$chId] = $chName;

            foreach ($items as $item) {
                // 统一时间格式为 YmdHis 供后续排序
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

// --- 3. 统一分箱写入 JSON ---
echo "⚙️ 正在执行分箱逻辑并写入 EPG/01-10 子目录...\n";

foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    if (empty($displayName)) continue;

    // 预处理：排序与去重
    usort($progList, function($a, $b) {
        return strcmp($a['startTime'], $b['startTime']);
    });
    $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));
    $jsonEncoded = json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // 别名列表
    $nameItem = trim($displayName);
    $aliases = [$nameItem];
    if (strcasecmp($nameItem, 'CCTV5+') === 0) {
        $aliases[] = str_replace('+', 'plus', $nameItem);
    }

    foreach ($aliases as $targetName) {
        $folderIdx = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
        $targetDir = $baseDir . $folderIdx . '/';

        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $safeName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $targetName);
        if (file_put_contents($targetDir . $safeName . '.json', $jsonEncoded)) {
            $globalFileCount++;
        }
    }
}

echo "\n✨ 任务圆满完成！";
echo "\n📊 总计生成文件: $globalFileCount 个";
echo "\n📂 存储路径: EPG/01-" . str_pad(ceil($globalFileCount / $filesPerFolder), 2, '0', STR_PAD_LEFT) . "\n";

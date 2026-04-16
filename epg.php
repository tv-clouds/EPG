<?php
/**
 * Ando EPG 分类处理器 - 适配版
 */

// 路径配置
$baseDir = __DIR__ . '/EPG/'; 

ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

// 定义要处理的文件
$xmlFilesToProcess = ['cn.xml', 'hk.xml', 'tw.xml', 'all.xml'];

$globalFileCount = 0;
$filesPerFolder = 900;

echo "🚀 开始解析 XML 并生成 JSON...\n";

$channels = [];
$channelNames = [];

// 1. 读取并汇总所有频道和节目信息
foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $baseDir . $fileName;
    if (!file_exists($filePath)) continue;

    $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    if (!$xml) continue;

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
    unset($xml);
}

// 2. 生成 JSON 并分配到 01-10 目录
foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    if (empty($displayName)) continue;

    $originalName = trim($displayName);
    $namesToGenerate = [$originalName];
    
    // 别名逻辑：CCTV5+ -> CCTV5Plus
    if (strcasecmp($originalName, 'CCTV5+') === 0) {
        $namesToGenerate[] = str_replace('+', 'plus', $originalName);
    }

    foreach ($namesToGenerate as $nameItem) {
        // 计算目标子目录 (01-10)
        $folderIndex = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
        $targetDir = $baseDir . $folderIndex . '/';

        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $safeName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $nameItem);
        
        // 排序与去重
        usort($progList, function($a, $b) { 
            return strcmp($a['startTime'], $b['startTime']); 
        });
        $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));

        $filePath = $targetDir . $safeName . '.json';
        if (file_put_contents($filePath, json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            $globalFileCount++;
        }
    }
}

echo "✨ 完成！共生成 $globalFileCount 个 JSON 文件。\n";

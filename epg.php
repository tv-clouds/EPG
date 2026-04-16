<?php
/**
 * Ando EPG 分类处理器 - 适配 GitHub Actions 预设目录版
 */

// 路径配置：现在所有 XML 都在 EPG 目录下
$baseDir = __DIR__ . '/EPG/'; 

ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

// 待处理的 XML 文件列表（对应你 workflow 中下载的文件名）
$xmlFilesToProcess = ['cn.xml', 'hk.xml', 'tw.xml', 'all.xml'];

// 计数器和分箱逻辑
$globalFileCount = 0;
$filesPerFolder = 900;

echo "🚀 开始处理 EPG 数据...\n";

$channels = [];
$channelNames = [];

// 1. 遍历读取所有 XML 文件
foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $baseDir . $fileName;
    if (!file_exists($filePath)) {
        echo "⚠️ 跳过不存在的文件: $fileName\n";
        continue;
    }

    echo "📖 正在解析: $fileName\n";
    $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    if (!$xml) continue;

    // 解析频道信息
    if (isset($xml->channel)) {
        foreach ($xml->channel as $ch) {
            $id = trim((string)$ch['id']);
            $name = trim((string)$ch->{'display-name'});
            if ($id && $name) $channelNames[$id] = $name;
        }
    }

    // 解析节目单信息
    if (isset($xml->programme)) {
        foreach ($xml->programme as $prog) {
            $chId = trim((string)$prog['channel']);
            $start = (string)$prog['start'];
            $stop = (string)$prog['stop'];
            
            // 提取时间并存入数组
            $channels[$chId][] = [
                'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
                'startTime' => substr($start, 0, 14),
                'stopTime'  => substr($stop, 0, 14),
                'program'   => trim((string)$prog->title)
            ];
        }
    }
    unset($xml); // 释放内存
}

// 2. 处理并生成 JSON 文件
foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    if (empty($displayName)) continue;

    // 核心逻辑：定义需要生成的名称
    $originalName = trim($displayName);
    $namesToGenerate = [$originalName];

    // 别名逻辑：CCTV5+ 兼容性处理
    if (strcasecmp($originalName, 'CCTV5+') === 0) {
        $namesToGenerate[] = str_replace('+', 'plus', $originalName);
    }

    foreach ($namesToGenerate as $nameItem) {
        // 计算分箱目录（01, 02...）
        $folderIndex = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
        $targetDir = $baseDir . $folderIndex . '/';

        // 注意：Actions 已经创建了目录，这里仅做二次保险
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        // 过滤文件名非法字符
        $safeName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $nameItem);
        
        // 排序与去重
        usort($progList, function($a, $b) { 
            return strcmp($a['startTime'], $b['startTime']); 
        });
        $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));

        // 写入文件
        $outputFile = $targetDir . $safeName . '.json';
        if (file_put_contents($outputFile, json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            $globalFileCount++;
        }
    }
}

echo "\n✨ 处理完成！";
echo "\n📦 总计解析频道: " . count($channels) . " 个";
echo "\n📂 生成 JSON 文件: $globalFileCount 个";
echo "\n📁 分布目录范围: 01 到 " . str_pad(ceil($globalFileCount / $filesPerFolder), 2, '0', STR_PAD_LEFT) . "\n";

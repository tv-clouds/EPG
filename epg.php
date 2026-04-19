<?php
/**
 * Ando EPG 分类处理器 - 自动化增强版
 * 功能：多源优先级过滤、路径自动对齐、旧数据物理清理
 */

// --- 1. 环境配置与路径对齐 ---
ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

// 强制指向脚本同级目录下的 EPG 文件夹
$scriptDir = str_replace('\\', '/', dirname(__FILE__));
$baseDir = rtrim($scriptDir, '/') . '/EPG/';

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

// 目标 XML 文件列表（按优先级排序，靠前的优先占坑）
$xmlFilesToProcess = ['t.xml', 'pl.xml' , 'boss.xml','hk.xml', 'tw.xml'];
$globalFileCount = 0;
$filesPerFolder = 900; 

echo "🚀 EPG 处理器启动...\n";
echo "📂 工作目录: $baseDir\n";

// --- 2. 预清理：删除旧的分箱文件夹 (防止 Git 状态混乱) ---
echo "🧹 正在清理旧的 JSON 数据...\n";
$oldFolders = glob($baseDir . '[0-9][0-9]', GLOB_ONLYDIR);
foreach ($oldFolders as $folder) {
    $files = glob($folder . '/*.json');
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($folder);
}

// --- 3. 解析逻辑 ---
$channels = [];
$channelNames = [];
$lockedChannelIds = []; // 用于优先级过滤：频道名 => 是否已占用

foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $baseDir . $fileName;
    
    if (!file_exists($filePath)) {
        echo "⚠️ 跳过不存在的文件: $fileName\n";
        continue;
    }

    echo "📖 正在解析: $fileName\n";
    $content = file_get_contents($filePath);
    if (empty($content)) continue;

    // 移除 XML 命名空间，防止 SimpleXML 解析失败
    $content = preg_replace('/<(tv|xmltv)[^>]*xmlns[:="][^>]*>/i', '<$1>', $content);
    $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    if (!$xml) {
        echo "❌ XML 格式错误: $fileName\n";
        continue;
    }

    $currentFileChannels = [];

    // 提取频道信息
    if (isset($xml->channel)) {
        foreach ($xml->channel as $ch) {
            $id = trim((string)$ch['id']);
            $name = trim((string)$ch->{"display-name"});
            if (!$id || !$name) continue;

            // 优先级检查：如果频道名已存在，则跳过后续低优先级源中的同名频道
            if (isset($lockedChannelIds[$name])) {
                continue; 
            }

            $channelNames[$id] = $name;
            $currentFileChannels[$id] = $name;
            $lockedChannelIds[$name] = true;
        }
    }

    // 提取节目单
    if (isset($xml->programme)) {
        foreach ($xml->programme as $prog) {
            $chId = trim((string)$prog['channel']);
            
            if (!isset($currentFileChannels[$chId])) {
                continue;
            }

            $start = (string)$prog['start'];
            $stop = (string)$prog['stop'];
            if ($chId && $start) {
                $channels[$chId][] = [
                    'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
                    'startTime' => substr($start, 0, 14),
                    'stopTime'  => substr($stop, 0, 14),
                    'program'   => trim((string)$prog->title)
                ];
            }
        }
    }
    unset($content, $xml, $currentFileChannels);
}

// --- 4. 写入逻辑 ---
echo "⚙️ 正在生成分箱 JSON 文件...\n";

if (count($channels) === 0) {
    echo "❌ 错误：未解析到任何有效数据，请检查 XML 文件内容。\n";
    exit;
}

foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    
    // 时间排序
    usort($progList, function($a, $b) {
        return strcmp($a['startTime'], $b['startTime']);
    });
    
    // 深度去重
    $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));
    $jsonEncoded = json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $nameItem = trim($displayName);
    $targets = [$nameItem]; 
    if (strpos($nameItem, '+') !== false) {
        $targets[] = str_replace('+', 'Plus', $nameItem);
    }

    foreach ($targets as $targetName) {
        // 分箱算法：每 900 个文件存入一个新目录
        $folderIdx = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
        $targetDir = $baseDir . $folderIdx . '/';

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // 文件名安全过滤
        $safeFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $targetName);
        $fullPath = $targetDir . $safeFileName . '.json';

        if (file_put_contents($fullPath, $jsonEncoded) !== false) {
            $globalFileCount++;
        }
    }
}

echo "\n✨ 处理完成！";
echo "\n📊 成功生成 $globalFileCount 个 JSON 文件。\n";

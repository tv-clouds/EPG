<?php
/**
 * Ando EPG 分类处理器 - 自动分箱版（带特殊频道别名）
 */

// 路径配置
$inputBaseDir  = __DIR__ . '/list/';
$outputBaseDir = __DIR__ . '/EPG/'; 

ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

$categories = ['CN', 'HK', 'TW'];

// 1. 初始化：清理并重新创建 EPG 目录
if (is_dir($outputBaseDir)) {
    exec("rm -rf " . escapeshellarg($outputBaseDir));
}
mkdir($outputBaseDir, 0777, true);

// 计数器和分箱逻辑
$globalFileCount = 0;
$filesPerFolder = 900;

foreach ($categories as $cat) {
    $inputDir = $inputBaseDir . $cat . '/';
    echo "📂 正在处理分类: [$cat]\n";

    if (!is_dir($inputDir)) continue;

    $xmlFiles = glob($inputDir . '*.xml');
    if (empty($xmlFiles)) continue;

    $channels = [];
    $channelNames = [];

    foreach ($xmlFiles as $file) {
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
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

    foreach ($channels as $id => $progList) {
        $displayName = $channelNames[$id] ?? $id;
        if (empty($displayName)) continue;

        // --- 核心逻辑：定义需要生成的名称列表 ---
        $namesToGenerate = [trim($displayName)];
        
        // 如果是 CCTV5+，则添加别名 CCTV5 Plus
        if (trim($displayName) === 'CCTV5+') {
            $namesToGenerate[] = 'CCTV5 Plus';
        }
        // ---------------------------------------

        foreach ($namesToGenerate as $nameItem) {
            // 计算分箱目录
            $folderIndex = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
            $targetDir = $outputBaseDir . $folderIndex . '/';
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
    echo "    ✅ 分类 [$cat] 处理完毕。\n";
    unset($channels, $channelNames);
}

echo "\n✨ 总计生成了 $globalFileCount 个文件，分布在 " . ceil($globalFileCount / $filesPerFolder) . " 个子目录中。\n";

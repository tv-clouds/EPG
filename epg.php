<?php
/**
 * Ando EPG 分类处理器 - 路径对齐修正版
 */

// --- 1. 路径强制对齐 ---
// 获取脚本所在目录的绝对路径
$scriptDir = str_replace('\\', '/', dirname(__FILE__));

// 无论脚本在哪，我们都强制指向脚本同级目录下的 EPG 文件夹
// 在 GitHub Actions 环境下，这通常是 /home/runner/work/仓库名/仓库名/EPG/
$baseDir = rtrim($scriptDir, '/') . '/EPG/';

// 检查目录，不存在则创建
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

ini_set('memory_limit', '1024M');
date_default_timezone_set('Asia/Shanghai');

// 目标文件列表
$xmlFilesToProcess = ['t.xml', 'hk.xml', 'tw.xml'];
$globalFileCount = 0;
$filesPerFolder = 900; 

echo "🚀 EPG 处理器启动...\n";
echo "📂 强制扫描目录: $baseDir\n";

$channels = [];
$channelNames = [];

// --- 2. 解析逻辑 (保持之前的命名空间修复) ---
foreach ($xmlFilesToProcess as $fileName) {
    $filePath = $baseDir . $fileName;
    
    if (!file_exists($filePath)) {
        // 尝试在上一级目录找一下（防御性逻辑）
        $altPath = dirname($baseDir) . '/' . $fileName;
        if (file_exists($altPath)) {
            $filePath = $altPath;
        } else {
            echo "⚠️ 找不到文件: $filePath\n";
            continue;
        }
    }

    echo "📖 正在解析：$filePath\n";
    $content = file_get_contents($filePath);
    if (empty($content)) continue;

    // 移除 XML 命名空间，防止 SimpleXML 解析失败
    $content = preg_replace('/<(tv|xmltv)[^>]*xmlns[:="][^>]*>/i', '<$1>', $content);
    
    $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
    if (!$xml) {
        echo "❌ 无法解析 XML 内容: $fileName\n";
        continue;
    }

    if (isset($xml->channel)) {
        foreach ($xml->channel as $ch) {
            $id = trim((string)$ch['id']);
            $name = trim((string)$ch->{"display-name"});
            if ($id && $name) $channelNames[$id] = $name;
        }
    }

    if (isset($xml->programme)) {
        foreach ($xml->programme as $prog) {
            $chId = trim((string)$prog['channel']);
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
    unset($content, $xml);
}

// --- 3. 写入逻辑 (保持不变) ---
echo "⚙️ 正在执行分箱写入...\n";

if (count($channels) === 0) {
    echo "❌ 错误：未解析到任何有效节目数据。\n";
    exit;
}

foreach ($channels as $id => $progList) {
    $displayName = $channelNames[$id] ?? $id;
    
    usort($progList, function($a, $b) {
        return strcmp($a['startTime'], $b['startTime']);
    });
    
    $finalProgList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));
    $jsonEncoded = json_encode($finalProgList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $nameItem = trim($displayName);
    $targets = [$nameItem]; 
    if (strpos($nameItem, '+') !== false) {
        $targets[] = str_replace('+', 'Plus', $nameItem);
    }

    foreach ($targets as $targetName) {
        $folderIdx = str_pad(ceil(($globalFileCount + 1) / $filesPerFolder), 2, '0', STR_PAD_LEFT);
        $targetDir = $baseDir . $folderIdx . '/';

        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $safeFileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $targetName);
        $fullPath = $targetDir . $safeFileName . '.json';

        if (file_put_contents($fullPath, $jsonEncoded) !== false) {
            $globalFileCount++;
        }
    }
}

echo "\n✨ 任务圆满完成！";
echo "\n📊 总计生成文件: $globalFileCount 个\n";

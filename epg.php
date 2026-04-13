<?php
/**
 * IPTV EPG 分类处理器
 * 1. 自动扫描 list/ 下的子目录 (CN, HK, TW)
 * 2. 在对应的 EPG/子目录 下生成中文名的 JSON
 */

$inputBaseDir = __DIR__ . '/list/';
$outputBaseDir = __DIR__ . '/EPG/';

ini_set('memory_limit', '1024M');

// 定义需要处理的分类目录
$categories = ['CN', 'HK', 'TW'];

foreach ($categories as $cat) {
    $inputDir = $inputBaseDir . $cat . '/';
    $outputDir = $outputBaseDir . $cat . '/';

    echo "📂 正在处理分类: [$cat]\n";

    // 检查输入目录是否存在
    if (!is_dir($inputDir)) {
        echo "   ⚠️ 跳过：未找到输入目录 $inputDir\n";
        continue;
    }

    // 确保输出子目录存在
    if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

    // 清理该分类下的旧数据
    array_map('unlink', glob($outputDir . '*.json'));

    // 获取该目录下的所有 XML
    $xmlFiles = glob($inputDir . '*.xml');
    if (empty($xmlFiles)) {
        echo "   ℹ️ 该目录下没有 XML 文件。\n";
        continue;
    }

    $channels = [];
    $channelNames = [];

    // 解析 XML
    foreach ($xmlFiles as $file) {
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
        if (!$xml) continue;

        // 映射频道名
        if (isset($xml->channel)) {
            foreach ($xml->channel as $ch) {
                $id = trim((string)$ch['id']);
                $name = trim((string)$ch->{'display-name'});
                if ($id && $name) $channelNames[$id] = $name;
            }
        }

        // 解析节目
        if (isset($xml->programme)) {
            foreach ($xml->programme as $prog) {
                $chId = trim((string)$prog['channel']);
                $start = (string)$prog['start'];
                $stop = (string)$prog['stop'];
                $channels[$chId][] = [
                    'start'     => substr($start, 8, 2) . ':' . substr($start, 10, 2),
                    'startTime' => substr($start, 0, 14),
                    'stopTime'  => substr($stop, 0, 14),
                    'program'   => (string)$prog->title
                ];
            }
        }
        unset($xml);
    }

    // 生成 JSON
    $fileCount = 0;
    foreach ($channels as $id => $progList) {
        $displayName = isset($channelNames[$id]) ? $channelNames[$id] : $id;
        $safeName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', trim($displayName));
        if (empty($safeName)) $safeName = $id;

        // 排序与去重
        usort($progList, function($a, $b) { return strcmp($a['startTime'], $b['startTime']); });
        $progList = array_values(array_map("unserialize", array_unique(array_map("serialize", $progList))));

        $filePath = $outputDir . $safeName . '.json';
        if (file_put_contents($filePath, json_encode($progList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            $fileCount++;
        }
    }
    echo "   ✅ 完成：生成了 $fileCount 个 JSON 文件。\n";
}

echo "\n----------------------------------------------------\n";
echo "🚀 所有任务执行完毕！时间: " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------------------\n";

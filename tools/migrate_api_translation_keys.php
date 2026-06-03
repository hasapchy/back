<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);
$scanDirs = [
    $baseDir.'/app/Http/Controllers',
    $baseDir.'/app/Repositories',
    $baseDir.'/app/Batch',
    $baseDir.'/app/Services',
    $baseDir.'/app/Http/Requests',
    $baseDir.'/app/Rules',
    $baseDir.'/app/Support',
];

$isTranslationKey = static function (string $text): bool {
    if (preg_match('/^(api|salary|warehouse_|units\.|validation\.|project_contract\.|product_history\.)/', $text)) {
        return true;
    }
    if (preg_match('/^[A-Z][A-Z0-9_]*$/', $text)) {
        return true;
    }
    if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]+)+$/', $text)) {
        return true;
    }

    return false;
};

$slugify = static function (string $text): string {
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    $text = preg_replace('/:\s*$/', '', $text) ?? $text;
    $text = strtr($text, [
        'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е', 'Ё' => 'ё',
        'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м',
        'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у',
        'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ',
        'Ы' => 'ы', 'Ь' => 'ь', 'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
    ]);
    $text = strtolower($text);
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '_', $text) ?? '';
    $text = trim($text, '_');

    if ($text === '') {
        $text = 'message';
    }

    if (strlen($text) > 48) {
        $text = substr($text, 0, 48);
        $text = rtrim($text, '_');
    }

    return $text;
};

$toSnake = static function (string $name): string {
    $name = preg_replace('/(Controller|Repository)$/', '', $name) ?? $name;

    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
};

$entityFromPath = static function (string $path) use ($toSnake): string {
    if (preg_match('/(\w+)Controller\.php$/', $path, $m)) {
        return $toSnake($m[1]);
    }
    if (preg_match('/(\w+)Repository\.php$/', $path, $m)) {
        return $toSnake($m[1]);
    }
    if (str_contains($path, 'BatchEntityActions')) {
        return 'batch';
    }
    if (str_contains($path, '/Services/')) {
        return 'services';
    }
    if (str_contains($path, '/Requests/')) {
        return 'requests';
    }
    if (str_contains($path, '/Rules/')) {
        return 'rules';
    }
    if (str_contains($path, '/Support/')) {
        return 'support';
    }

    return 'common';
};

$containsCyrillic = static fn (string $text): bool => (bool) preg_match('/[\x{0400}-\x{04FF}]/u', $text);

$entries = [];
$replacements = [];

foreach ($scanDirs as $dir) {
    if (! is_dir($dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }
        $entity = $entityFromPath($path);
        $pattern = "/__\(\s*'((?:\\\\'|[^'])*)'\s*(?:,\s*\[[^\]]*\])?\s*\)/u";
        if (! preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($matches[1] as $i => $match) {
            $raw = str_replace("\\'", "'", $match[0]);
            if ($isTranslationKey($raw)) {
                continue;
            }
            $slug = $slugify($raw);
            $key = "api.{$entity}.{$slug}";
            $suffix = 2;
            while (isset($entries[$key]) && $entries[$key]['ru'] !== $raw && $entries[$key]['en'] !== $raw) {
                $key = "api.{$entity}.{$slug}_{$suffix}";
                $suffix++;
            }
            if (! isset($entries[$key])) {
                $entries[$key] = [
                    'ru' => $containsCyrillic($raw) ? $raw : $raw,
                    'en' => $containsCyrillic($raw) ? $raw : $raw,
                ];
            }
            $replacements[] = [
                'path' => $path,
                'from' => $matches[0][$i][0],
                'to' => "__('{$key}'",
                'key' => $key,
                'text' => $raw,
            ];
        }
    }
}

$existingRu = require $baseDir.'/resources/lang/ru/api.php';
$existingEn = require $baseDir.'/resources/lang/en/api.php';

$nestedSet = static function (array &$root, string $dotKey, string $value): void {
    $parts = explode('.', $dotKey);
    if ($parts[0] === 'api') {
        array_shift($parts);
    }
    $ref = &$root;
    foreach ($parts as $part) {
        if (! isset($ref[$part]) || ! is_array($ref[$part])) {
            $ref[$part] = [];
        }
        $ref = &$ref[$part];
    }
    $ref = $value;
};

foreach ($entries as $fullKey => $langs) {
    $nestedSet($existingRu, $fullKey, $langs['ru']);
    $nestedSet($existingEn, $fullKey, $langs['en']);
}

$export = static function (array $array, int $indent = 1) use (&$export): string {
    $pad = str_repeat('    ', $indent);
    $lines = ["\n"];
    foreach ($array as $key => $value) {
        $k = is_int($key) ? $key : "'".str_replace("'", "\\'", (string) $key)."'";
        if (is_array($value)) {
            $lines[] = "{$pad}{$k} => [".$export($value, $indent + 1)."{$pad}],\n";
        } else {
            $lines[] = "{$pad}{$k} => '".str_replace("'", "\\'", (string) $value)."',\n";
        }
    }

    return implode('', $lines);
};

$phpHeader = "<?php\n\ndeclare(strict_types=1);\n\nreturn [";
$phpFooter = "\n];\n";

file_put_contents($baseDir.'/resources/lang/ru/api.php', $phpHeader.$export($existingRu, 0).$phpFooter);
file_put_contents($baseDir.'/resources/lang/en/api.php', $phpHeader.$export($existingEn, 0).$phpFooter);

$byFile = [];
foreach ($replacements as $item) {
    $byFile[$item['path']][] = $item;
}

$updatedFiles = 0;
foreach ($byFile as $path => $items) {
    usort($items, static fn ($a, $b) => strlen($b['from']) <=> strlen($a['from']));
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }
    $original = $content;
    foreach ($items as $item) {
        $content = str_replace("__('{$item['text']}'", "__('{$item['key']}'", $content);
        $content = str_replace('__("'.$item['text'].'"', '__("'.$item['key'].'"', $content);
    }
    if ($content !== $original) {
        file_put_contents($path, $content);
        $updatedFiles++;
    }
}

echo 'keys='.count($entries).PHP_EOL;
echo 'updated_files='.$updatedFiles.PHP_EOL;

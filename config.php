<?php
/**
 * config.php —— 安全加载 config.env 配置（支持注释）
 * 与 Python 共用同一份 config.env
 */

function load_env_config($env_file = __DIR__ . '/config.env') {
    if (!file_exists($env_file)) {
        http_response_code(500);
        die("<h2>❌ 配置错误</h2><p>缺少配置文件：<code>config.env</code></p><p>请复制 <code>config.env.example</code> 并填写实际值。</p>");
    }

    $config = [];
    $lines = @file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        die("<h2>❌ 读取错误</h2><p>无法读取 config.env，请检查文件权限。</p>");
    }

    foreach ($lines as $line) {
        $line = trim($line);
        // 跳过空行和注释
        if ($line === '' || strpos($line, '#') === 0) continue;

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // 去掉首尾引号
            $value = preg_replace('/^([\'"])(.*)\1$/', '$2', $value);
            $config[$key] = $value;
        }
    }

    return $config;
}

$env = load_env_config();

// 定义数据库常量（带安全默认值）
define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
define('DB_USER', $env['DB_USER'] ?? 'root');
define('DB_PASS', $env['DB_PASSWORD'] ?? '');
define('DB_NAME', $env['DB_NAME'] ?? 'game_coin');
?>

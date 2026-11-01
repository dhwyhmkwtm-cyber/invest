<?php
declare(strict_types=1);

// アプリ共通の初期化。公開ディレクトリのエントリポイントと bin/ のCLIから読み込む。

defined('MAILER_ROOT') || define('MAILER_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Mailer\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

mb_internal_encoding('UTF-8');

/**
 * 設定・DBを読み込んだアプリを返す。
 * MAILER_CONFIG 環境変数で設定ファイルの場所を上書きできる (テスト用)。
 */
if (!function_exists('mailer_app')) {
    function mailer_app(): \Mailer\App
    {
        static $app = null;
        if ($app === null) {
            $path = getenv('MAILER_CONFIG') ?: __DIR__ . '/config/config.php';
            $app = \Mailer\App::fromConfigFile($path);
        }
        return $app;
    }
}

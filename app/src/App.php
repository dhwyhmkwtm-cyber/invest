<?php
declare(strict_types=1);

namespace Mailer;

use Mailer\Ses\CurlTransport;
use Mailer\Ses\SesClient;
use Mailer\Ses\Transport;

/**
 * 依存オブジェクトの組み立て役 (簡易DIコンテナ)。
 */
final class App
{
    private ?Db $db = null;
    private ?SesClient $ses = null;
    private ?Transport $transport = null;

    public function __construct(public readonly Config $config, public readonly Clock $clock)
    {
        date_default_timezone_set((string)$config->get('timezone', 'Asia/Tokyo'));
    }

    public static function fromConfigFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("設定ファイルがありません: {$path} (config.sample.php をコピーして作成してください)");
        }
        $data = require $path;
        return new self(new Config($data), new Clock());
    }

    public function db(): Db
    {
        return $this->db ??= Db::connect($this->config);
    }

    public function setDb(Db $db): void
    {
        $this->db = $db;
    }

    public function transport(): Transport
    {
        return $this->transport ??= new CurlTransport();
    }

    public function setTransport(Transport $t): void
    {
        $this->transport = $t;
        $this->ses = null;
    }

    public function ses(): SesClient
    {
        if ($this->config->get('aws.mode') === 'local') {
            return $this->ses ??= new Local\LocalSesClient($this);
        }
        return $this->ses ??= new SesClient(
            (string)$this->config->get('aws.region'),
            (string)$this->config->get('aws.access_key_id'),
            (string)$this->config->get('aws.secret_access_key'),
            $this->transport(),
            $this->clock,
        );
    }

    public function settings(): Settings
    {
        return new Settings($this->db(), $this->clock);
    }

    public function suppressions(): SuppressionList
    {
        return new SuppressionList($this->db(), $this->clock);
    }

    public function notifier(): Notifier
    {
        return new Notifier($this->config, $this->ses(), $this->transport(), $this->clock, $this->settings());
    }

    public function unsubscribeToken(): UnsubscribeToken
    {
        return new UnsubscribeToken((string)$this->config->get('app_secret'), (string)$this->config->get('public_base_url'));
    }

    public function varDir(): string
    {
        $dir = (string)$this->config->get('var_dir', MAILER_ROOT . '/var');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    public function log(string $channel, string $message): void
    {
        $line = sprintf("[%s] %s\n", $this->clock->now()->format('Y-m-d H:i:s'), $message);
        @file_put_contents($this->varDir() . '/' . $channel . '-' . $this->clock->now()->format('Ym') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}

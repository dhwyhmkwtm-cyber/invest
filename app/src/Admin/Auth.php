<?php
declare(strict_types=1);

namespace Mailer\Admin;

use Mailer\App;

/** 管理画面の認証 (ID・パスワード + IP制限 + ログイン試行制限 + CSRF) */
final class Auth
{
    public function __construct(private App $app)
    {
    }

    public function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function ipAllowed(): bool
    {
        $allowed = (array)$this->app->config->get('admin.allowed_ips', []);
        if ($allowed === []) {
            return true;
        }
        $ip = $this->clientIp();
        foreach ($allowed as $rule) {
            if (self::ipMatch($ip, (string)$rule)) {
                return true;
            }
        }
        return false;
    }

    public static function ipMatch(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }
        [$net, $bits] = explode('/', $rule, 2);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $netBin, $bytes) !== 0) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return ($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask);
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off');
        session_name((string)$this->app->config->get('admin.session_name', 'mailer_admin'));
        session_set_cookie_params([
            'lifetime' => 0, 'path' => dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
        $life = (int)$this->app->config->get('admin.session_lifetime', 7200);
        if (isset($_SESSION['uid'], $_SESSION['at']) && time() - (int)$_SESSION['at'] > $life) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if (isset($_SESSION['uid'])) {
            $_SESSION['at'] = time();
        }
    }

    public function user(): ?string
    {
        return isset($_SESSION['uid']) ? (string)$_SESSION['user'] : null;
    }

    public function tooManyAttempts(): bool
    {
        $since = $this->app->clock->now()->modify('-15 minutes')->format('Y-m-d H:i:s');
        return (int)$this->app->db()->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?',
            [$this->clientIp(), $since]
        ) >= 10;
    }

    public function login(string $username, string $password): bool
    {
        $db = $this->app->db();
        $row = $db->one('SELECT * FROM admin_users WHERE username = ?', [$username]);
        $ok = $row !== null && password_verify($password, $row['password_hash']);
        if (!$row) {
            password_verify($password, '$2y$10$usesomesillystringforsaltusesomesillystringfor..'); // タイミング差を減らす
        }
        $db->insert('login_attempts', ['ip' => $this->clientIp(), 'username' => mb_substr($username, 0, 50), 'success' => $ok ? 1 : 0, 'created_at' => $this->app->clock->nowSql()]);
        if (!$ok) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$row['id'];
        $_SESSION['user'] = $row['username'];
        $_SESSION['at'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $db->run('UPDATE admin_users SET last_login_at = ? WHERE id = ?', [$this->app->clock->nowSql(), $row['id']]);
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function checkCsrf(): bool
    {
        return isset($_POST['_csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['_csrf']);
    }

    public function audit(string $action, array $detail = []): void
    {
        $this->app->db()->insert('audit_log', [
            'actor' => $this->user() ?? '-', 'action' => $action,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE), 'created_at' => $this->app->clock->nowSql(),
        ]);
    }
}

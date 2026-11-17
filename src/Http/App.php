<?php
declare(strict_types=1);

namespace Ablet\Http;

use Ablet\Support\Database;
use Ablet\Support\Env;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $code, public readonly mixed $detail = null)
    {
        parent::__construct($code);
    }
}

/** Nucleo HTTP: sessione sicura, CSRF, limitazione delle richieste, instradamento, risposte JSON. */
final class App
{
    /** @var array<int,array{0:string,1:string,2:callable,3:?string}> metodo, regex, gestore, ruolo richiesto */
    private static array $routes = [];
    private static ?array $user = null;
    private static string $scriptNonce = '';

    public static function route(string $method, string $pattern, callable $handler, ?string $role = null): void
    {
        self::$routes[] = [$method, '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', $pattern) . '$#', $handler, $role];
    }

    public static function run(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: microphone=(self), camera=(), geolocation=()');
        self::$scriptNonce = base64_encode(random_bytes(18));
        header("Content-Security-Policy: media-src 'self' blob:; script-src 'self' 'nonce-" . self::$scriptNonce . "'; default-src 'self'; img-src 'self' data: blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if ($https) { header('Strict-Transport-Security: max-age=31536000'); }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = self::base();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        if (!str_starts_with($path, '/api/')) {
            self::shell();
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            ini_set('session.use_strict_mode', '1');
            session_name('ablet_sid');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $https, 'path' => (self::base() ?: '') . '/']);
            session_start();
            if (isset($_SESSION['last']) && time() - $_SESSION['last'] > 3600) { // inattività
                $_SESSION = [];
                session_regenerate_id(true);
            }
            $_SESSION['last'] = time();
            $_SESSION['csrf'] ??= bin2hex(random_bytes(24));

            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method !== 'GET' && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
                throw new ApiError(403, 'csrf_non_valido');
            }
            foreach (self::$routes as [$m, $re, $handler, $role]) {
                if ($m === $method && preg_match($re, $path, $args)) {
                    if ($role !== null) {
                        self::requireRole($role);
                        // L'identità è già verificata e memorizzata: liberare il lock prima di chiamate lente.
                        // Login, logout e /me conservano invece la sessione scrivibile.
                        session_write_close();
                    }
                    $out = $handler(array_filter($args, 'is_string', ARRAY_FILTER_USE_KEY));
                    echo json_encode($out ?? ['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    return;
                }
            }
            throw new ApiError(404, 'non_trovato');
        } catch (ApiError $e) {
            http_response_code($e->status);
            echo json_encode(['error' => $e->getMessage(), 'detail' => $e->detail], JSON_UNESCAPED_UNICODE);
        } catch (\DomainException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            // Log tecnico: classe, file e riga. Mai contenuti, parametri o chiavi.
            error_log(sprintf('[ABLET] %s in %s:%d', $e::class, basename($e->getFile()), $e->getLine()));
            if (Env::get('APP_ENV') === 'development') { error_log('[ABLET] ' . $e->getMessage()); }
            http_response_code(500);
            echo json_encode(['error' => 'errore_interno']);
        }
    }

    public static function input(): array
    {
        $limit = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/voice/') ? 6000000 : 600000;
        $raw = file_get_contents('php://input', false, null, 0, $limit + 1) ?: '';
        if (strlen($raw) > $limit) { throw new ApiError(413, 'richiesta_troppo_grande'); }
        if ($raw === '') { return []; }
        $d = json_decode($raw, true, 32);
        if (!is_array($d)) { throw new ApiError(400, 'json_non_valido'); }
        return $d;
    }

    public static function user(): array
    {
        if (self::$user === null) {
            $id = $_SESSION['uid'] ?? null;
            if (!$id) { throw new ApiError(401, 'non_autenticato'); }
            $st = Database::pdo()->prepare("SELECT id, pseudonym, role, login, display_alias FROM users WHERE id = ? AND status = 'active'");
            $st->execute([$id]);
            self::$user = $st->fetch() ?: null;
            if (self::$user === null) { $_SESSION = []; throw new ApiError(401, 'non_autenticato'); }
            self::$user['id'] = (int)self::$user['id'];
        }
        return self::$user;
    }

    public static function requireRole(string $role): array
    {
        $u = self::user();
        if ($u['role'] !== $role) { throw new ApiError(403, 'non_autorizzato'); }
        return $u;
    }

    /** Finestra fissa su tabella: $max richieste ogni $windowS secondi per chiave. */
    public static function rateLimit(string $bucket, int $max, int $windowS): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, UTC_TIMESTAMP(3), 1)
            ON DUPLICATE KEY UPDATE hits = IF(window_start < UTC_TIMESTAMP(3) - INTERVAL ? SECOND, 1, hits + 1),
                                    window_start = IF(window_start < UTC_TIMESTAMP(3) - INTERVAL ? SECOND, UTC_TIMESTAMP(3), window_start)')
            ->execute([$bucket, $windowS, $windowS]);
        $st = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ?');
        $st->execute([$bucket]);
        if ((int)$st->fetchColumn() > $max) { throw new ApiError(429, 'troppe_richieste'); }
    }

    public static function audit(string $action, string $entity, ?int $entityId, array $meta = []): void
    {
        Database::pdo()->prepare('INSERT INTO audit_log (actor_id, action, entity, entity_id, meta, ip_hash) VALUES (?,?,?,?,?,?)')
            ->execute([$_SESSION['uid'] ?? null, $action, $entity, $entityId, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . Env::get('APP_SECRET_KEY', ''))]);
    }

    public static function login(): array
    {
        $in = self::input();
        $login = trim((string)($in['login'] ?? ''));
        self::rateLimit('login:' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . mb_strtolower($login)), 8, 600);
        $st = Database::pdo()->prepare("SELECT id, password_hash, role FROM users WHERE login = ? AND status = 'active'");
        $st->execute([$login]);
        $u = $st->fetch();
        // Verifica sempre un hash, anche per utenti inesistenti, per non rivelarne l'esistenza dai tempi di risposta.
        $ok = password_verify((string)($in['password'] ?? ''), $u['password_hash'] ?? password_hash('assente', PASSWORD_DEFAULT));
        if (!$u || !$ok) { throw new ApiError(401, 'credenziali_non_valide'); }
        $expected = $in['expected_role'] ?? null;
        if ($expected !== null && (!in_array($expected, ['student', 'psychologist'], true) || $u['role'] !== $expected)) {
            throw new ApiError(403, 'accesso_non_corrispondente');
        }
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int)$u['id'], 'last' => time(), 'csrf' => bin2hex(random_bytes(24))];
        self::audit('login', 'users', (int)$u['id']);
        return self::me();
    }

    public static function me(): array
    {
        $u = null;
        try { $u = self::user(); } catch (ApiError) {}
        return ['csrf' => $_SESSION['csrf'], 'user' => $u ? ['role' => $u['role'], 'alias' => $u['display_alias'] ?: $u['login'], 'pseudonym' => $u['pseudonym']] : null];
    }

    public static function logout(): array
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        return ['csrf' => $_SESSION['csrf'], 'user' => null];
    }

    /** Percorso di installazione: '' alla radice del dominio, '/ablet' in una sottocartella (anche con public/ non impostata come document root). */
    public static function base(): string
    {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $dir = preg_replace('#/public$#', '', rtrim($dir, '/')) ?? '';
        return $dir === '/' ? '' : $dir;
    }

    private static function shell(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache'); // la pagina è sempre richiesta alla rete; senza rete risponde il service worker
        // Una versione di tutti i moduli evita dipendenze obsolete e doppie istanze di app.js.
        $assets = glob(ABLET_ROOT . '/public/assets/*.js') ?: [];
        $assets[] = ABLET_ROOT . '/public/assets/app.css';
        sort($assets);
        $v = substr(hash('sha256', implode('', array_map(static fn($file) => (string)hash_file('sha256', $file), $assets))), 0, 16);
        $b = htmlspecialchars(self::base(), ENT_QUOTES);
        $imports = [];
        foreach ($assets as $asset) {
            if (str_ends_with($asset, '.js')) {
                $url = self::base() . '/assets/' . basename($asset);
                $imports[$url] = $url . '?v=' . $v;
            }
        }
        $scriptNonce = self::$scriptNonce;
        $importMap = json_encode(['imports' => $imports], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        echo <<<HTML
<!doctype html>
<html lang="it" data-base="$b">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ABLET — supporto all'autoregolazione dello studio</title>
<meta name="description" content="ABLET accompagna lo studio attraverso pianificazione, monitoraggio e auto-riflessione. Una piattaforma per studenti, educatori e psicologi.">
<meta name="theme-color" content="#2f6f6a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ABLET">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="$b/manifest.webmanifest">
<link rel="icon" type="image/png" href="$b/assets/icons/favicon-64.png">
<link rel="apple-touch-icon" href="$b/assets/icons/apple-touch-icon.png">
<link rel="stylesheet" href="$b/assets/app.css?v=$v">
</head>
<body>
<a class="skip" href="#main">Vai al contenuto</a>
<div id="app" aria-live="off"></div>
<noscript><p class="pad">ABLET richiede JavaScript.</p></noscript>
<script type="importmap" nonce="$scriptNonce">$importMap</script>
<script type="module" src="$b/assets/app.js?v=$v"></script>
</body>
</html>
HTML;
    }
}

<?php
declare(strict_types=1);

// Front controller. La document root del server web deve puntare a questa cartella (public/).

require dirname(__DIR__) . '/src/bootstrap.php';

use Ablet\Domain\Agent\PlatformRules;
use Ablet\Http\App;
use Ablet\Http\PsyApi;
use Ablet\Http\StudentApi;
use Ablet\Support\Database;

// Con il server integrato di PHP i file statici sono serviti direttamente.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && !str_ends_with($f, '.php')) { return false; }
    if ((str_ends_with($f, '/install.php') || str_ends_with($f, '/refresh.php') || str_ends_with($f, '/manual.php')) && is_file($f)) { require $f; return true; }
}

// Installazione non ancora eseguita: la pagina principale porta all'installatore invece di mostrare un accesso inutilizzabile.
if (!\Ablet\Support\Env::hasConfig() && !str_contains((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/api/')) {
    header('Location: ' . App::base() . '/install.php', true, 302);
    exit;
}

// Le chiamate ai provider possono durare decine di secondi: si chiede più tempo, se l'hosting lo consente.
@set_time_limit(120);

App::route('GET', '/api/health', static function (): array {
    try {
        $n = (int)Database::pdo()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        return ['app' => 'ABLET', 'database' => 'ok', 'migrations' => $n, 'platform_rules' => PlatformRules::VERSION];
    } catch (Throwable) {
        return ['app' => 'ABLET', 'database' => 'non raggiungibile'];
    }
});
App::route('GET', '/api/me', [App::class, 'me']);
App::route('POST', '/api/login', [App::class, 'login']);
App::route('POST', '/api/logout', [App::class, 'logout']);
\Ablet\Http\AccountApi::registerRoutes();
StudentApi::register();
\Ablet\Http\VoiceApi::register();
PsyApi::register();
App::run();

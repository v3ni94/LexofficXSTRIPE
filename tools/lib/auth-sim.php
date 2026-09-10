<?php
/**
 * Prueffaelle der Anmeldesicherheit gegen eine temporaere MariaDB (Audit 10.09.2026). Aufrufer: tools/auth-check.sh.
 *   SMARTEINZUG_CONFIG=<sandbox> php tools/lib/auth-sim.php <repo> seed          Benutzer mit TOTP anlegen, gibt user_id und code aus
 *   SMARTEINZUG_CONFIG=<sandbox> php tools/lib/auth-sim.php <repo> verify <user_id> <code>   twofa_verify_user -> "ok=1|0"
 *   SMARTEINZUG_CONFIG=<sandbox> php tools/lib/auth-sim.php <repo> cleanup       login_attempts_cleanup gegen alte Zeilen
 */
declare(strict_types=1);
define('LOG_SERVICE', 'cli');
require $argv[1] . '/php-ionos/bin/_cli.php';
require $argv[1] . '/tools/lib/test-guard.php';
require_once $argv[1] . '/php-ionos/app/totp.php';
require_once $argv[1] . '/php-ionos/app/crypto.php';
$pdo = db();
switch ($argv[2] ?? '') {
    case 'seed':
        $id = uuid4();
        $secret = totp_generate_secret();
        $pdo->prepare('INSERT INTO users (id, email, password_hash, totp_secret_encrypted, totp_enabled, totp_confirmed_at, totp_last_step) VALUES (?, ?, ?, ?, 1, NOW(), NULL)')
            ->execute([$id, 'totp-' . substr($id, 0, 8) . '@auth.test', 'x', encrypt_value($secret)]);
        // Code fuer den aktuellen Zeitschritt; die Sekunde innerhalb des Schritts bleibt Spielraum fuer die Parallelaufrufe
        echo "user_id=$id\ncode=", totp_code($secret, time()), "\nsekunde_im_schritt=", time() % 30, "\n";
        break;
    case 'verify':
        $u = user_load((string)$argv[3]);
        echo 'ok=', twofa_verify_user($u, (string)$argv[4]) ? 1 : 0, "\n";
        break;
    case 'cleanup':
        $pdo->exec("INSERT INTO login_attempts (email, ip, success, stage, created_at) VALUES ('alt@auth.test', '10.0.0.1', 0, 'password', DATE_SUB(NOW(), INTERVAL 40 DAY)), ('neu@auth.test', '10.0.0.1', 0, 'password', NOW())");
        echo 'geloescht=', login_attempts_cleanup(), "\n";
        echo 'verbleibend=', (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE email IN ('alt@auth.test','neu@auth.test')")->fetchColumn(), "\n";
        break;
    default:
        exit(2);
}

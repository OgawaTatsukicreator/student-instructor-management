<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $c = $GLOBALS['config']['db'];
        $pdo = new PDO('mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
            $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

function query(string $sql, array $params = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s;
}

function transaction(callable $work): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try { $result = $work($pdo); $pdo->commit(); return $result; }
    catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); }
            catch (Throwable $cleanup) { diagnostic_log('transaction.rollback', $cleanup, 503); }
        }
        throw $e;
    }
}

function management_lock(): void
{
    if (!query("SELECT name FROM system_locks WHERE name = 'management' FOR UPDATE")->fetch()) {
        throw new RuntimeException('Management lock is missing');
    }
}

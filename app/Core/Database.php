<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
final class Database {
    private static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (!self::$pdo) {
            self::$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3308') . ';dbname=' . (getenv('DB_DATABASE') ?: 'salinas_ti') . ';charset=utf8mb4', (string)getenv('DB_USERNAME'), (string)getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
            self::$pdo->exec("SET time_zone='-04:00'");
        }
        return self::$pdo;
    }
    public static function run(string $sql, array $p=[]): \PDOStatement { $s=self::pdo()->prepare($sql); $s->execute($p); return $s; }
    public static function all(string $sql, array $p=[]): array { return self::run($sql,$p)->fetchAll(); }
    public static function one(string $sql, array $p=[]): ?array { return self::run($sql,$p)->fetch() ?: null; }
    public static function transaction(callable $fn): mixed {
        $db=self::pdo(); if($db->inTransaction()) return $fn(); $db->beginTransaction();
        try { $v=$fn(); $db->commit(); return $v; } catch(\Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    public static function audit(string $table, mixed $id, string $action, mixed $before, mixed $after, ?int $actor): void {
        self::run('INSERT INTO auditoria(entidade,entidade_id,acao,antes_json,depois_json,autor_id) VALUES(?,?,?,?,?,?)',[$table,(string)$id,$action,json_encode($before,JSON_UNESCAPED_UNICODE),json_encode($after,JSON_UNESCAPED_UNICODE),$actor]);
    }
}

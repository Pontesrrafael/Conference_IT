<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
spl_autoload_register(function(string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    }
});
$config = is_file(ROOT . '/.env') ? parse_ini_file(ROOT . '/.env', false, INI_SCANNER_RAW) : [];
foreach ($config ?: [] as $k => $v) if (getenv($k) === false) putenv($k . '=' . $v);
date_default_timezone_set('America/Cuiaba');
function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path): never { header('Location: ' . $path, true, 303); exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function options(array $rows, mixed $selected = null): string {
    $html = '<option value="">Selecione</option>';
    foreach ($rows as $r) $html .= '<option value="' . e($r['id']) . '"' . ((string)$selected === (string)$r['id'] ? ' selected' : '') . '>' . e($r['nome']) . '</option>';
    return $html;
}
/** Tema da interface por navegador (cookie "tema"): claro, escuro ou automático (segue o sistema operacional). */
function tema(): string { $t = $_COOKIE['tema'] ?? ''; return in_array($t, ['claro', 'escuro'], true) ? $t : 'auto'; }
function attr_tema(): string { return tema() === 'auto' ? '' : ' data-tema="' . tema() . '"'; }
/** Seletor Claro · Automático · Escuro. Funciona sem JavaScript (/tema) e, com ele, troca na hora (assets/tema.js). */
function seletor_tema(string $classe = ''): string {
    $voltar = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if (!str_starts_with($voltar, '/') || str_starts_with($voltar, '//')) $voltar = '/';
    $h = '<nav class="tema-seletor ' . e($classe) . '" aria-label="Tema da interface">';
    foreach (['claro' => ['☀', 'Claro'], 'auto' => ['◐', 'Automático'], 'escuro' => ['☾', 'Escuro']] as $v => [$icone, $rotulo]) {
        $h .= '<a href="/tema?' . e(http_build_query(['valor' => $v, 'voltar' => $voltar])) . '" data-tema-valor="' . $v . '" aria-pressed="' . (tema() === $v ? 'true' : 'false') . '" title="Tema ' . e(mb_strtolower($rotulo)) . '"><span aria-hidden="true">' . $icone . '</span> ' . e($rotulo) . '</a>';
    }
    return $h . '</nav>';
}

<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

function check(bool $condition): void {
    if (!$condition) { throw new RuntimeException('Validação incorreta.'); }
}
function invalid(callable $call): void {
    try { $call(); } catch (App\Exception\ApiError) { return; }
    throw new RuntimeException('Entrada inválida aceita.');
}
check(App\Validation\Input::birth('2008-02-29', '2026-03-01') === '2008-02-29');
invalid(fn () => App\Validation\Input::birth('2008-02-29', '2026-02-28'));
check(App\Validation\Input::normalize(" A\u{0301}na ", true) === 'Ána');
check(App\Validation\Input::normalize('  conteúdo  ') === '  conteúdo  ');
invalid(fn () => App\Validation\Input::normalize("\xFF"));
foreach (['{}', '{"a":1,"b":2,"c":3}', '["x",-1,"bad"]'] as $value) {
    invalid(fn () => App\Validation\Input::cursor(base64_encode($value), 'x'));
}
echo "Calendário, UTF-8, NFC e cursores: OK\n";

<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

function check(bool $condition): void {
    if (!$condition) { throw new RuntimeException('Validação incorreta.'); }
}
function invalid(callable $call): void {
    try { $call(); } catch (App\ApiError) { return; }
    throw new RuntimeException('Entrada inválida aceita.');
}
check(App\Input::birth('2008-02-29', '2026-03-01') === '2008-02-29');
invalid(fn () => App\Input::birth('2008-02-29', '2026-02-28'));
check(App\Input::text(str_repeat('😀', 32), 32) === str_repeat('😀', 32));
invalid(fn () => App\Input::text(str_repeat('😀', 33), 32));
check(App\Input::text(" A\u{0301}na ", 32, true) === 'Ána');
foreach (['{}', '{"a":1,"b":2,"c":3}', '["x",-1,"bad"]'] as $value) {
    invalid(fn () => App\Input::cursor(base64_encode($value), 'x'));
}
echo "Calendário, pontos de código, NFC e cursores: OK\n";

<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use RoundlyConsulting\Approvals\Enums\ConfirmationRefusal;

dataset('translation files', ['confirmations']);

dataset('translated locales', ['sk']);

it('ships the same keys in every language', function (string $file, string $locale): void {
    $en = Arr::dot(require __DIR__.'/../../resources/lang/en/'.$file.'.php');
    $translated = Arr::dot(require __DIR__.'/../../resources/lang/'.$locale.'/'.$file.'.php');

    expect($en)->not->toBeEmpty()
        ->and(array_keys($translated))->toBe(array_keys($en));
})->with('translation files')->with('translated locales');

it('keeps every placeholder in every language', function (string $file, string $locale): void {
    $en = Arr::dot(require __DIR__.'/../../resources/lang/en/'.$file.'.php');
    $translated = Arr::dot(require __DIR__.'/../../resources/lang/'.$locale.'/'.$file.'.php');

    $placeholders = static function (mixed $line): array {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', is_string($line) ? $line : '', $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    };

    foreach ($en as $key => $line) {
        expect($placeholders($translated[$key] ?? null))->toBe($placeholders($line), "{$locale}: {$file}.{$key}");
    }
})->with('translation files')->with('translated locales');

it('has a message for every refusal code, and nothing else', function (): void {
    $refused = (require __DIR__.'/../../resources/lang/en/confirmations.php')['refused'];

    expect(array_keys($refused))->toBe(array_column(ConfirmationRefusal::cases(), 'value'));
});

it('serves slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(trans('approvals::confirmations.refused.used'))->toBe('Toto potvrdenie už bolo použité.');

    app()->setLocale('en');

    expect(trans('approvals::confirmations.refused.used'))->toBe('This confirmation has already been used.');
});

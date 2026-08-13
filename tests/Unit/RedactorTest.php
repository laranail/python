<?php

declare(strict_types=1);

use Simtabi\Laranail\Python\Support\Redactor;

it('masks a secret it was given, wherever it appears', function (): void {
    $r = (new Redactor)->remember('super-secret-token-value');

    expect($r->redact('Traceback: headers={"Authorization": "Bearer super-secret-token-value"}'))
        ->not->toContain('super-secret-token-value')
        ->toContain(Redactor::MASK);
});

it('masks a secret embedded in a url, which is where requests puts it', function (): void {
    $r = (new Redactor)->remember('abcd1234efgh');

    expect($r->redact('GET https://svc.internal/predict?api_key=abcd1234efgh failed'))
        ->not->toContain('abcd1234efgh');
});

it('masks the longest secret first so no recognisable tail survives', function (): void {
    $r = (new Redactor)->remember('token')->remember('token-with-suffix');

    expect($r->redact('value=token-with-suffix'))->not->toContain('suffix');
});

it('ignores a secret too short to mask safely', function (): void {
    // Masking "ab" would redact half the alphabet out of unrelated text.
    $r = (new Redactor)->remember('ab');

    expect($r->known())->toBe([])
        ->and($r->redact('a table of abbreviations'))->toBe('a table of abbreviations');
});

it('falls back to pattern matching for secrets it never saw', function (): void {
    expect((new Redactor)->redact('config: api_key=NOTREGISTERED123 and more'))
        ->not->toContain('NOTREGISTERED123');
});

it('clamps a long traceback to a tail', function (): void {
    $long = str_repeat('x', 5000);

    expect(strlen((new Redactor)->tail($long, 100)))->toBeLessThanOrEqual(104);
});

it('redacts before clamping, so a secret near the end cannot slip through', function (): void {
    $r = (new Redactor)->remember('leaked-secret-value');

    expect($r->tail(str_repeat('x', 200) . ' leaked-secret-value', 60))
        ->not->toContain('leaked-secret-value');
});

it('takes a list of secrets and skips non-strings', function (): void {
    $r = (new Redactor)->rememberAll(['first-secret-x', 123, null, 'second-secret-x']);

    expect($r->known())->toHaveCount(2);
});

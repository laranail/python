<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Support;

/**
 * Masks secrets out of text on its way to a log or an exception message.
 *
 * ## Why literal values, not just patterns
 *
 * Pattern matching on `api_key=…` catches the obvious shapes and misses the
 * rest, and stderr is exactly where it misses. A Python traceback embeds local
 * variables; a `requests` exception embeds the full URL, query-string token
 * included; a library can print anything it likes on the way down.
 *
 * So the primary mechanism is exact: every secret **this package injected into
 * the call** — the bearer token, the API key, the HMAC secret, each per-script
 * env value — is registered and masked by value. If the package put it in, the
 * package can find it again, wherever it surfaced and whatever framing it
 * picked up. Pattern matching stays as a second pass for secrets that came from
 * somewhere else.
 *
 * Values shorter than {@see MIN_SECRET_LENGTH} are ignored. Masking a two-
 * character value would redact half the alphabet out of unrelated text and make
 * the output useless.
 */
final class Redactor
{
    /**
     * Below this, masking does more damage to the text than the secret is worth.
     */
    private const int MIN_SECRET_LENGTH = 6;

    public const string MASK = '[redacted]';

    /**
     * Key-ish names whose adjacent value gets masked, for secrets the package
     * never saw.
     */
    private const string PATTERN = '/\b(api[_-]?key|apikey|token|secret|password|passwd|authorization|auth)\b\s*[=:]\s*["\']?([^\s"\'&,;]{4,})/i';

    /** @var list<string> */
    private array $literals = [];

    /**
     * Register a secret to mask by exact value.
     */
    public function remember(?string $secret): self
    {
        if ($secret === null) {
            return $this;
        }

        $secret = trim($secret);

        if (strlen($secret) < self::MIN_SECRET_LENGTH || in_array($secret, $this->literals, true)) {
            return $this;
        }

        $this->literals[] = $secret;

        // Longest first, so a secret that contains another is masked whole
        // rather than leaving a recognisable tail behind.
        usort($this->literals, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this;
    }

    /**
     * Register several secrets at once; non-string values are skipped.
     *
     * @param iterable<array-key, mixed> $secrets
     */
    public function rememberAll(iterable $secrets): self
    {
        foreach ($secrets as $secret) {
            if (is_string($secret)) {
                $this->remember($secret);
            }
        }

        return $this;
    }

    public function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        foreach ($this->literals as $literal) {
            $text = str_replace($literal, self::MASK, $text);
        }

        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $m): string => $m[1] . '=' . self::MASK,
            $text,
        );
    }

    /**
     * Redact and clamp to a length, so a 40 MB traceback cannot reach a log.
     */
    public function tail(string $text, int $maxChars): string
    {
        $text = trim($this->redact($text));

        if ($maxChars <= 0 || strlen($text) <= $maxChars) {
            return $text;
        }

        return '…' . substr($text, -$maxChars);
    }

    /** @return list<string> */
    public function known(): array
    {
        return $this->literals;
    }
}

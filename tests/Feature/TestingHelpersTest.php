<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature;

use Simtabi\Laranail\Python\Enums\Transport;
use Simtabi\Laranail\Python\Exceptions\PythonException;
use Simtabi\Laranail\Python\Facades\Python;
use Simtabi\Laranail\Python\Testing\RecordedCall;
use Simtabi\Laranail\Python\Tests\TestCase;
use Simtabi\Laranail\Python\ValueObjects\PythonCall;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

final class TestingHelpersTest extends TestCase
{
    public function test_a_bare_fake_succeeds_with_nothing(): void
    {
        Python::fake();

        $result = Python::run('fastapi:predict', ['x' => 1]);

        self::assertTrue($result->ok);
        self::assertSame([], $result->data);
    }

    public function test_a_keyed_fake_answers_by_target(): void
    {
        Python::fake(['embed' => ['vector' => [0.1, 0.2]]]);

        self::assertSame([0.1, 0.2], Python::run('embed')->get('vector'));
    }

    public function test_a_glob_stands_in_for_a_whole_service(): void
    {
        Python::fake(['fastapi:*' => ['served' => true]]);

        self::assertTrue(Python::run('fastapi:predict')->get('served'));
        self::assertTrue(Python::run('fastapi:embed')->get('served'));
    }

    public function test_a_callable_fake_sees_the_call(): void
    {
        Python::fake(fn (PythonCall $call): PythonResult => PythonResult::ok(['echo' => $call->target]));

        self::assertSame('anything', Python::run('anything')->get('echo'));
    }

    public function test_a_fake_reports_itself_rather_than_pretending_to_be_healthy(): void
    {
        // A fake left installed on a deployed path should be loud, not green.
        Python::fake();

        $result = Python::run('fastapi');

        self::assertSame(Transport::Fake, $result->via);
        self::assertSame('faked', Python::healthAll()['fastapi']->via->label());
    }

    public function test_it_asserts_what_was_sent(): void
    {
        Python::fake();

        Python::run('embed', ['text' => 'hello']);
        Python::run('embed', ['text' => 'again']);

        Python::assertSent();
        Python::assertSentTo('embed');
        Python::assertSentTimes('embed', 2);
        Python::assertSent(fn (RecordedCall $c): bool => $c->call->payload['text'] === 'hello');
    }

    public function test_it_asserts_nothing_was_sent(): void
    {
        Python::fake();

        Python::assertNothingSent();
    }

    public function test_asserting_without_a_fake_is_an_error_rather_than_a_pass(): void
    {
        $this->expectException(PythonException::class);

        Python::assertNothingSent();
    }

    public function test_forgetting_transports_drops_the_fake(): void
    {
        Python::fake();
        self::assertTrue(Python::isFaked());

        Python::forgetTransports();

        self::assertFalse(Python::isFaked());
    }
}

<?php

namespace Tests\Feature;

use App\Support\NamedLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NamedLockTest extends TestCase
{
    public function test_it_refuses_to_lock_inside_a_transaction(): void
    {
        $lock = new NamedLock(DB::connection());

        $this->expectException(LogicException::class);

        DB::transaction(fn () => $lock->run('test-lock', 0, fn () => null));
    }

    public function test_it_releases_the_lock_when_the_callback_throws(): void
    {
        $lock = new NamedLock(DB::connection());

        try {
            $lock->run('test-lock', 0, fn () => throw new RuntimeException('payment gateway down'));
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(1, (int) DB::selectOne("SELECT IS_FREE_LOCK('test-lock') AS free")->free);
    }

    public function test_it_returns_the_callback_result(): void
    {
        $this->assertSame('done', (new NamedLock(DB::connection()))->run('test-lock', 0, fn () => 'done'));
    }

    public function test_a_failed_release_does_not_hide_the_callbacks_exception(): void
    {
        Exceptions::fake();
        $lock = new NamedLock($this->connectionWhoseReleaseFails());

        $thrown = null;
        try {
            $lock->run('test-lock', 0, fn () => throw new RuntimeException('payment gateway down'));
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('payment gateway down', $thrown->getMessage());

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'connection lost');
    }

    public function test_a_failed_release_after_success_still_returns_the_result(): void
    {
        Exceptions::fake();
        $lock = new NamedLock($this->connectionWhoseReleaseFails());

        // The work committed; throwing now would invite a retry that repeats it.
        $this->assertSame('done', $lock->run('test-lock', 0, fn () => 'done'));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'connection lost');
    }

    private function connectionWhoseReleaseFails(): ConnectionInterface
    {
        /** @var ConnectionInterface&MockInterface $connection */
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->allows('transactionLevel')->andReturn(0);
        $connection->allows('selectOne')->with('SELECT GET_LOCK(?, ?) AS acquired', Mockery::any())->andReturn((object) ['acquired' => 1]);
        $connection->allows('selectOne')->with('SELECT RELEASE_LOCK(?) AS released', Mockery::any())->andThrow(new RuntimeException('connection lost'));

        return $connection;
    }
}

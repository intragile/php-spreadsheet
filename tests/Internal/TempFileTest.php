<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal;

use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Internal\TempFile;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;

final class TempFileTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_creates_an_empty_file_in_the_system_temp_directory_by_default(): void
    {
        $path = $this->track(TempFile::create('sps_test_'));

        $this->assertFileExists($path);
        $this->assertSame(0, filesize($path));
        $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($path)));
        $this->assertStringStartsWith('sps_test_', basename($path));
    }

    public function test_creates_the_file_in_the_given_directory(): void
    {
        $directory = $this->directory();

        $path = $this->track(TempFile::create('sps_test_', $directory));

        $this->assertSame(realpath($directory), realpath(dirname($path)));
    }

    public function test_a_missing_directory_is_rejected(): void
    {
        $this->expectException(SpreadsheetException::class);
        $this->expectExceptionMessage('does not exist or is not writable');

        TempFile::create('sps_test_', sys_get_temp_dir().'/sps_missing_'.bin2hex(random_bytes(6)));
    }

    public function test_publish_moves_the_file_with_umask_permissions(): void
    {
        $directory = $this->directory();
        $temp = TempFile::create('sps_test_', $directory);
        file_put_contents($temp, 'payload');
        $target = $this->track($directory.'/out.txt');

        TempFile::publish($temp, $target);

        $this->assertFileDoesNotExist($temp);
        $this->assertSame('payload', file_get_contents($target));
        $this->assertSame(0666 & ~umask(), fileperms($target) & 0777);
    }

    public function test_publish_to_an_unreachable_target_throws_and_keeps_the_temp_file(): void
    {
        $temp = $this->track(TempFile::create('sps_test_', $this->directory()));

        try {
            TempFile::publish($temp, sys_get_temp_dir().'/sps_missing_'.bin2hex(random_bytes(6)).'/out.txt');
            $this->fail('publish should fail');
        } catch (SpreadsheetException $e) {
            $this->assertStringContainsString('Cannot move the finished file', $e->getMessage());
        }

        $this->assertFileExists($temp);
    }

    public function test_delete_removes_the_file_and_tolerates_a_missing_one(): void
    {
        $path = TempFile::create('sps_test_', $this->directory());

        TempFile::delete($path);
        $this->assertFileDoesNotExist($path);

        self::withoutPhpErrors(static fn () => TempFile::delete($path));
        $this->assertFileDoesNotExist($path);
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir().'/sps_tempfile_'.bin2hex(random_bytes(6));
        mkdir($directory);

        return $this->track($directory);
    }

    private function track(string $path): string
    {
        $this->cleanup[] = $path;

        return $path;
    }
}

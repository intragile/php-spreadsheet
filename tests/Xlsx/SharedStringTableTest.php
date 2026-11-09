<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Xlsx;

use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use IntrAgile\Spreadsheet\Xlsx\SharedStringTable;

final class SharedStringTableTest extends TestCase
{
    public function test_in_memory(): void
    {
        $table = new SharedStringTable(10);
        $table->add('alpha');
        $table->add('');
        $table->add('árvíztűrő');

        $this->assertSame('alpha', $table->get(0));
        $this->assertSame('', $table->get(1));
        $this->assertSame('árvíztűrő', $table->get(2));
        $this->assertSame(3, $table->count());

        $table->close();
    }

    public function test_shared_strings_spill(): void
    {
        $table = new SharedStringTable(10);

        for ($i = 0; $i < 1000; $i++) {
            $table->add("string {$i} ".str_repeat('é', $i % 7));
        }

        $this->assertSame('string 0 ', $table->get(0));
        $this->assertSame('string 999 '.str_repeat('é', 999 % 7), $table->get(999));
        $this->assertSame('string 500 '.str_repeat('é', 500 % 7), $table->get(500));
        $this->assertSame('string 9 '.str_repeat('é', 2), $table->get(9));
        $this->assertSame('string 10 '.str_repeat('é', 3), $table->get(10));

        // Reads may be interleaved with further additions.
        $table->add('late');
        $this->assertSame('late', $table->get(1000));
        $this->assertSame('string 11 '.str_repeat('é', 4), $table->get(11));

        $table->close();
    }

    public function test_spilled_strings_of_any_size_in_any_order(): void
    {
        $table = new SharedStringTable(0);
        $expected = [];

        for ($i = 0; $i < 300; $i++) {
            $expected[$i] = str_repeat(chr(65 + $i % 26), $i % 50 === 0 ? 70_000 + $i : $i * 7);
            $table->add($expected[$i]);
        }

        $order = range(0, 299);
        mt_srand(7);
        shuffle($order);

        foreach ([...range(0, 299), ...$order, ...range(299, 0, -1)] as $index) {
            $this->assertSame($expected[$index], $table->get($index), "entry {$index}");
        }

        $table->close();
    }

    public function test_spilled_empty_and_binary_strings(): void
    {
        $table = new SharedStringTable(0);
        $table->add('');
        $table->add("a\0b\nc");

        $this->assertSame('', $table->get(0));
        $this->assertSame("a\0b\nc", $table->get(1));

        $table->close();
    }

    public function test_index_out_of_range_is_empty(): void
    {
        $table = new SharedStringTable(1);
        $table->add('a');
        $table->add('b');

        $this->assertSame('', $table->get(2));
        $this->assertSame('', $table->get(-1));

        $table->close();
    }

    public function test_close_removes_the_spill_file(): void
    {
        $before = glob(sys_get_temp_dir().'/sps_sst*') ?: [];
        $table = new SharedStringTable(0);
        $table->add('x');
        $this->assertSame('x', $table->get(0));
        $during = glob(sys_get_temp_dir().'/sps_sst*') ?: [];
        $table->close();
        $after = glob(sys_get_temp_dir().'/sps_sst*') ?: [];

        $this->assertCount(count($before) + 1, $during);
        $this->assertCount(count($before), $after);
    }
}

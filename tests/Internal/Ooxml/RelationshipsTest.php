<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal\Ooxml;

use IntrAgile\Spreadsheet\Internal\Ooxml\Relationships;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RelationshipsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function targets(): iterable
    {
        yield 'relative' => ['xl/', 'worksheets/sheet1.xml', 'xl/worksheets/sheet1.xml'];
        yield 'absolute' => ['xl/', '/xl/styles.xml', 'xl/styles.xml'];
        yield 'package root' => ['', 'xl/workbook.xml', 'xl/workbook.xml'];
        yield 'parent and current segments' => ['xl/worksheets/', '../theme/./theme1.xml', 'xl/theme/theme1.xml'];
        yield 'backslashes' => ['xl/', 'worksheets\\sheet1.xml', 'xl/worksheets/sheet1.xml'];
        yield 'climbs out of the package' => ['xl/', '../../etc/passwd', null];
        yield 'empty' => ['', '/', null];
    }

    #[DataProvider('targets')]
    public function test_resolves_targets_inside_the_package(string $base, string $target, ?string $expected): void
    {
        $this->assertSame($expected, Relationships::resolve($base, $target));
    }

    public function test_relationships_path_of_a_part(): void
    {
        $this->assertSame('xl/_rels/workbook.xml.rels', Relationships::relationshipsPath('xl/workbook.xml'));
        $this->assertSame('_rels/workbook.xml.rels', Relationships::relationshipsPath('workbook.xml'));
    }
}

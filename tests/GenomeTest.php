<?php
declare(strict_types = 1);

namespace Tests\Innmind\Genome;

use Innmind\Genome\{
    Genome,
    Gene,
    Gene\Name,
};
use PHPUnit\Framework\TestCase;

class GenomeTest extends TestCase
{
    public function testInterface()
    {
        $genome = new Genome(
            $first = Gene::template(new Name('foo/bar')),
            $second = Gene::template(new Name('foo/baz'))
        );

        $this->assertTrue($genome->contains('foo/bar'));
        $this->assertTrue($genome->contains('foo/baz'));
        $this->assertFalse($genome->contains('foobar'));
        $this->assertSame($first, $genome->get('foo/bar'));
        $this->assertSame($second, $genome->get('foo/baz'));
    }
}

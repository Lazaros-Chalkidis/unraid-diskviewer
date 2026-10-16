<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once(__DIR__."/../../../source/include/diskviewer_api.php");

final class DiskViewerEndpointTest extends TestCase
{
    public function testSeagateSmartFormat(): void
    {
        $string = '  9 Power_On_Hours          0x0032   074   074   000    Old_age   Always       -       23266 (48 159 0)';
        $rawById = DiskViewerEndpoint::extractRawById($string);
        $this->assertSame($rawById[9],23266);
    }

    public function testWesternDigitalSmartFormat(): void
    {
        $string = '  9 Power_On_Hours          0x0032   073   073   000    Old_age   Always       -       19782';
        $rawById = DiskViewerEndpoint::extractRawById($string);
        $this->assertSame($rawById[9],19782);
    }
}
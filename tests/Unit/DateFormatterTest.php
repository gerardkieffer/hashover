<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use HashOver\View\DateFormatter;
use HashOver\View\Translator;
use PHPUnit\Framework\TestCase;

final class DateFormatterTest extends TestCase
{
    public function testRelativeDates(): void
    {
        $dates = new DateFormatter(new Translator('en'), 'UTC', true);
        $now = new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('UTC'));

        $cases = [
            '2026-10-04 11:59:30' => 'just now',
            '2026-10-04 11:59:00' => '1 minute ago',
            '2026-10-04 09:00:00' => '3 hours ago',
            '2026-10-02 12:00:00' => '2 days ago',
            '2026-07-01 12:00:00' => '3 months ago',
            '2020-01-01 12:00:00' => '6 years ago',
            '2026-10-05 12:00:00' => 'just now',
        ];

        foreach ($cases as $date => $expected) {
            self::assertSame($expected, $dates->display(new \DateTimeImmutable($date, new \DateTimeZone('UTC')), $now), $date);
        }
    }

    public function testAbsoluteDatesUseTheConfiguredTimezone(): void
    {
        $dates = new DateFormatter(new Translator('en'), 'Europe/Luxembourg', false);
        $date = new \DateTimeImmutable('2026-01-15 12:00:00', new \DateTimeZone('UTC'));

        self::assertStringContainsString('1:00', $dates->display($date));
        self::assertSame('2026-01-15T12:00:00+00:00', $dates->iso($date));
    }
}

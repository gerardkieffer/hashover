<?php

declare(strict_types=1);

namespace HashOver\View;

/**
 * Comment dates, either relative ("3 days ago") or absolute, in the
 * configured time zone and language.
 */
final readonly class DateFormatter
{
    private \DateTimeZone $timezone;

    public function __construct(
        private Translator $translator,
        string $timezone,
        private bool $relative,
    ) {
        $this->timezone = new \DateTimeZone($timezone);
    }

    /** Text shown for a date */
    public function display(\DateTimeImmutable $date, ?\DateTimeImmutable $now = null): string
    {
        return $this->relative ? $this->relative($date, $now ?? new \DateTimeImmutable()) : $this->absolute($date);
    }

    public function relative(\DateTimeImmutable $date, \DateTimeImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $date->getTimestamp());

        return match (true) {
            $seconds < 60 => $this->translator->translate('date.just_now'),
            $seconds < 3600 => $this->translator->plural('date.minutes_ago', intdiv($seconds, 60)),
            $seconds < 86400 => $this->translator->plural('date.hours_ago', intdiv($seconds, 3600)),
            $seconds < 86400 * 30 => $this->translator->plural('date.days_ago', intdiv($seconds, 86400)),
            $seconds < 86400 * 365 => $this->translator->plural('date.months_ago', intdiv($seconds, 86400 * 30)),
            default => $this->translator->plural('date.years_ago', intdiv($seconds, 86400 * 365)),
        };
    }

    /** Full date and time, e.g. for a tooltip or when relative dates are off */
    public function absolute(\DateTimeImmutable $date): string
    {
        $local = $date->setTimezone($this->timezone);

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($this->translator->language, \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT, $this->timezone);
            $formatted = $formatter->format($local);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $local->format('Y-m-d H:i');
    }

    /** Machine-readable date for <time datetime> */
    public function iso(\DateTimeImmutable $date): string
    {
        return $date->format(\DateTimeInterface::ATOM);
    }
}

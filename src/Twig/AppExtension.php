<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('full_datetime', $this->formatFullDateTime(...)),
            new TwigFilter('short_datetime', $this->formatShortDateTime(...)),
        ];
    }

    public function formatFullDateTime(\DateTimeInterface $date): string
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        $datePart = \IntlDateFormatter::formatObject($date, [\IntlDateFormatter::FULL, \IntlDateFormatter::NONE], $locale);
        $timePart = \IntlDateFormatter::formatObject($date, 'HH:mm', $locale);

        return sprintf('%s, %s', $datePart, $timePart);
    }

    public function formatShortDateTime(\DateTimeInterface $date): string
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        $datePart = \IntlDateFormatter::formatObject($date, 'EEE, d MMM y', $locale);
        $timePart = \IntlDateFormatter::formatObject($date, 'HH:mm', $locale);

        return sprintf('%s, %s', $datePart, $timePart);
    }
}

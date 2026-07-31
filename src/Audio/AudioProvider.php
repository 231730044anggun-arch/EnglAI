<?php
declare(strict_types=1);
namespace EnglAI\Audio;
interface AudioProvider
{
    /** @return array<string,mixed> */
    public function configuration(array $activityContent): array;
}

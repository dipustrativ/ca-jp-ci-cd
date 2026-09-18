<?php

declare(strict_types=1);

namespace CarpetAvenue\ReservationsFix\Model\Queue;

class ProcessMove
{
    private string $incrementId;

    public function __construct(string $incrementid)
    {
        $this->incrementId = $incrementid;
    }

    /**
     * @return string
     */
    public function getIncrementid(): string
    {
        return $this->incrementId;
    }
}

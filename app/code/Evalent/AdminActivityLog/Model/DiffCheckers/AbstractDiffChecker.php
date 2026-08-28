<?php

declare(strict_types=1);

namespace Evalent\AdminActivityLog\Model\DiffCheckers;

abstract class AbstractDiffChecker
{

    abstract public function checkDifferences($object): array;
}

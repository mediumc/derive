<?php

declare(strict_types=1);

namespace Derive\Utils;

abstract class AbstractPathPreset
{
    public function getId(): string
    {
        $class = (new \ReflectionClass($this))->getShortName();
        return strtolower(str_replace('Preset', '', $class));
    }

    abstract public function getPath(): string;

    abstract public function getWalletSoftwareName(): string;

    abstract public function getWalletSoftwareVersionInfo(): string;

    abstract public function getNote(): string;
}

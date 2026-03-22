<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class WasabiPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/84'/c'/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Wasabi';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '?';
    }

    public function getNote(): string
    {
        return 'Bip84';
    }
}

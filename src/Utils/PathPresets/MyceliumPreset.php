<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class MyceliumPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/44'/c'/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Mycelium';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '>= 2.0';
    }

    public function getNote(): string
    {
        return 'Bip44';
    }
}

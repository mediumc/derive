<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class Multibit_hd_44Preset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/44'/c'/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Multibit HD (Bip44)';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '?';
    }

    public function getNote(): string
    {
        return 'Bip44';
    }
}

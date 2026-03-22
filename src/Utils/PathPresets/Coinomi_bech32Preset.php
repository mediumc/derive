<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class Coinomi_bech32Preset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/84'/c'/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Coinomi (bech32)';
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

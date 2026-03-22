<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class Bip84Preset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/84'/c'/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Bip84 Compat';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return 'n/a';
    }

    public function getNote(): string
    {
        return 'Bip84';
    }
}

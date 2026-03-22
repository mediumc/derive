<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class Samourai_p2shPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/49'/c'/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Samourai (p2sh)';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '?';
    }

    public function getNote(): string
    {
        return 'Bip49';
    }
}

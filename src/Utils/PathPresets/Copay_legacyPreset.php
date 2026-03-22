<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class Copay_legacyPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/45'/2147483647/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Copay Legacy';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '< 1.2';
    }

    public function getNote(): string
    {
        return 'Bip45 special cosign idx';
    }
}

<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class ElectrumPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Electrum';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '2.0+';
    }

    public function getNote(): string
    {
        return 'Single account wallet';
    }
}

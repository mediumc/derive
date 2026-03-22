<?php

declare(strict_types=1);

namespace Derive\Utils;

use CoinParams\CoinParams;

class NetworkCoinFactory
{
    /**
     * @return array<string, array{name: string, bip44: mixed}>
     */
    public static function getNetworkCoinsList(): array
    {
        $coins = CoinParams::get_all_coins();

        $list = [];
        foreach ($coins as $sym => $c) {
            foreach ($c as $net => $info) {
                if (empty($info['prefixes']['extended']['xpub']['public'])
                    || empty($info['prefixes']['extended']['xpub']['private'])) {
                    continue;
                }
                $suffix = $net === 'main' ? '' : "-$net";
                $symbol = $sym . $suffix;
                $list[$symbol] = [
                    'name' => $info['name'],
                    'bip44' => $info['prefixes']['bip44'],
                ];
            }
        }
        return $list;
    }

    public static function getNetworkCoinInstance(string $coin): FlexNetwork
    {
        return new FlexNetwork($coin);
    }
}

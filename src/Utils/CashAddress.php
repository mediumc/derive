<?php

declare(strict_types=1);

namespace Derive\Utils;

use BitWasp\Bech32;
use function BitWasp\Bech32;

class CashAddress
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    public static function old2new(string $oldAddress): string
    {
        $alphabetMap = self::getAlphabetMap();
        $bytes = [0];

        for ($x = 0; $x < strlen($oldAddress); $x++) {
            if (!array_key_exists($oldAddress[$x], $alphabetMap)) {
                throw new \InvalidArgumentException('Unexpected character in address');
            }
            $value = $alphabetMap[$oldAddress[$x]];
            $carry = $value;
            for ($j = 0; $j < count($bytes); $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry >>= 8;
            }
        }

        for ($numZeros = 0; $numZeros < strlen($oldAddress) && $oldAddress[$numZeros] === '1'; $numZeros++) {
            $bytes[] = 0;
        }

        $answer = [];
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            $answer[] = $bytes[$i];
        }
        $version = $answer[0];
        $payload = array_slice($answer, 1, count($answer) - 5);

        if (count($payload) % 4 !== 0) {
            throw new \InvalidArgumentException('Unexpected address length');
        }

        [$addressType, $realNet] = match ($version) {
            0x00 => [0, true],    // P2PKH
            0x05 => [1, true],    // P2SH
            0x6f => [0, false],   // Testnet P2PKH
            0xc4 => [1, false],   // Testnet P2SH
            0x1c => [0, true],    // BitPay P2PKH
            0x28 => [1, true],    // BitPay P2SH
            default => throw new \InvalidArgumentException('Unknown address type: 0x' . dechex($version)),
        };

        $encodedSize = (count($payload) - 20) / 4;
        $versionByte = ($addressType << 3) | (int)$encodedSize;
        $data = array_merge([$versionByte], $payload);

        $payloadConverted = Bech32\convertBits($data, count($data), 8, 5, true);
        if ($realNet) {
            $arr = array_merge(self::getExpandPrefix(), $payloadConverted, [0, 0, 0, 0, 0, 0, 0, 0]);
            $ret = 'bitcoincash:';
        } else {
            $arr = array_merge(self::getExpandPrefixTestnet(), $payloadConverted, [0, 0, 0, 0, 0, 0, 0, 0]);
            $ret = 'bchtest:';
        }

        $mod = Bech32\polyMod($arr, count($arr));
        $checksum = [0, 0, 0, 0, 0, 0, 0, 0];
        for ($i = 0; $i < 8; $i++) {
            $checksum[$i] = ($mod >> (5 * (7 - $i))) & 0x1f;
        }
        $combined = array_merge($payloadConverted, $checksum);
        for ($i = 0; $i < count($combined); $i++) {
            $ret .= self::CHARSET[$combined[$i]];
        }
        return $ret;
    }

    /**
     * @return array<string, int>
     */
    private static function getAlphabetMap(): array
    {
        return [
            '1' => 0, '2' => 1, '3' => 2, '4' => 3, '5' => 4, '6' => 5, '7' => 6,
            '8' => 7, '9' => 8, 'A' => 9, 'B' => 10, 'C' => 11, 'D' => 12, 'E' => 13,
            'F' => 14, 'G' => 15, 'H' => 16, 'J' => 17, 'K' => 18, 'L' => 19, 'M' => 20,
            'N' => 21, 'P' => 22, 'Q' => 23, 'R' => 24, 'S' => 25, 'T' => 26, 'U' => 27,
            'V' => 28, 'W' => 29, 'X' => 30, 'Y' => 31, 'Z' => 32, 'a' => 33, 'b' => 34,
            'c' => 35, 'd' => 36, 'e' => 37, 'f' => 38, 'g' => 39, 'h' => 40, 'i' => 41,
            'j' => 42, 'k' => 43, 'm' => 44, 'n' => 45, 'o' => 46, 'p' => 47, 'q' => 48,
            'r' => 49, 's' => 50, 't' => 51, 'u' => 52, 'v' => 53, 'w' => 54, 'x' => 55,
            'y' => 56, 'z' => 57,
        ];
    }

    /**
     * @return int[]
     */
    private static function getExpandPrefix(): array
    {
        return [2, 9, 20, 3, 15, 9, 14, 3, 1, 19, 8, 0];
    }

    /**
     * @return int[]
     */
    private static function getExpandPrefixTestnet(): array
    {
        return [2, 3, 8, 20, 5, 19, 20, 0];
    }
}

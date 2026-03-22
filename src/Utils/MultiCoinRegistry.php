<?php

declare(strict_types=1);

namespace Derive\Utils;

use BitWasp\Bitcoin\Key\Deterministic\Slip132\PrefixRegistry;
use BitWasp\Bitcoin\Script\ScriptType;

class MultiCoinRegistry extends PrefixRegistry
{
    /** @var array<string, mixed> */
    private array $keyTypeMap;

    /**
     * @param array<string, array{public: string, private: string}> $extendedMap
     */
    public function __construct(array $extendedMap)
    {
        $map = [];
        $x = $extendedMap['xpub'] ?? null;
        $y = $extendedMap['ypub'] ?? null;
        $Y = $extendedMap['Ypub'] ?? null;
        $z = $extendedMap['zpub'] ?? null;
        $Z = $extendedMap['Zpub'] ?? null;

        $st = [
            'x' => [ScriptType::P2PKH],
            'X' => [ScriptType::P2SH, ScriptType::P2PKH],
            'y' => [ScriptType::P2SH, ScriptType::P2WKH],
            'Y' => [ScriptType::P2SH, ScriptType::P2WSH, ScriptType::P2PKH],
            'z' => [ScriptType::P2WKH],
            'Z' => [ScriptType::P2WSH, ScriptType::P2PKH],
        ];

        $this->keyTypeMap = [
            'x' => $x,
            'X' => $x,
            'y' => $y,
            'Y' => $Y,
            'z' => $z,
            'Z' => $Z,
        ];

        $entries = [
            [$x, $st['x']],
            [$x, $st['X']],
            [$y, $st['y']],
            [$Y, $st['Y']],
            [$z, $st['z']],
            [$Z, $st['Z']],
        ];

        foreach ($entries as [$prefixData, $scriptType]) {
            if (!$this->hasValidPrefixes($prefixData)) {
                continue;
            }
            $prefixList = [$prefixData['private'], $prefixData['public']];
            foreach ($prefixList as &$val) {
                $val = str_replace('0x', '', $val);
            }
            unset($val);
            $type = implode('|', $scriptType);
            $map[$type] = $prefixList;
        }

        parent::__construct($map);
    }

    private function hasValidPrefixes(mixed $data): bool
    {
        return is_array($data) && !empty($data['private']) && !empty($data['public']);
    }

    public function prefixBytesByKeyType(string $keyType): mixed
    {
        return $this->keyTypeMap[$keyType] ?? null;
    }
}

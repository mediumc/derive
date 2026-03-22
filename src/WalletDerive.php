<?php

declare(strict_types=1);

namespace Derive;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\EcAdapter\EcAdapterFactory;
use BitWasp\Bitcoin\Crypto\EcAdapter\Impl\PhpEcc\Serializer\Key\PublicKeySerializer;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use BitWasp\Bitcoin\Exceptions\InvalidDerivationException;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeySequence;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Key\KeyToScript\KeyToScriptHelper;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\RawExtendedKeySerializer;
use BitWasp\Buffertools\Parser;
use CoinParams\CoinParams;
use Derive\Utils\CashAddress;
use Derive\Utils\MultiCoinRegistry;
use Derive\Utils\NetworkCoinFactory;
use kornrunner\Keccak;
use Mdanter\Ecc\EccFactory;
use Mdanter\Ecc\Serializer\Point\UncompressedPointSerializer;

class WalletDerive
{
    private array $params;
    private HierarchicalKeyFactory $hkf;

    public function __construct(array $params)
    {
        $this->params = $params;
        $this->hkf = new HierarchicalKeyFactory();
    }

    public function deriveKeys(string $key): array
    {
        return $this->deriveKeysWorker($key);
    }

    private function deriveKeysWorker(string $key): array
    {
        $coin = $this->params['coin'];
        [$symbol] = explode('-', $coin);
        $addrs = [];

        $bip44Coin = $this->getCoinBip44($coin);

        $network = NetworkCoinFactory::getNetworkCoinInstance($coin);
        Bitcoin::setNetwork($network);
        $keyType = $this->getKeyTypeFromCoinAndKey($coin, $key);

        $master = $this->fromExtended($coin, $key, $network, $keyType);

        $start = $this->params['startindex'];
        $end = $start + $this->params['numderive'];

        if ($this->params['includeroot']) {
            $this->deriveKeyWorker($coin, $symbol, $network, $addrs, $master, $keyType, null, 'm');
        }

        $pathBase = is_numeric($this->params['path'][0])
            ? 'm/' . $this->params['path']
            : $this->params['path'];

        $pparts = explode('/', $pathBase);

        $iterPart = null;
        foreach ($pparts as $idx => $pp) {
            if (($pp[0] ?? '') === 'x') {
                $iterPart = $idx;
            }
        }
        if ($iterPart === null) {
            $pparts[] = 'x';
        }

        $pathNormal = implode('/', $pparts);
        $pathMask = $this->buildPathMask($pathNormal, $coin, $bip44Coin);

        for ($i = $start; $i < $end; $i++) {
            $path = sprintf($pathMask, $i);
            $derived = $this->derivePath($master, $path);
            $this->deriveKeyWorker($coin, $symbol, $network, $addrs, $derived, $keyType, $i, $path);
        }

        return $addrs;
    }

    /**
     * Replaces path variables (x, c, v, a) with actual values.
     * Uses per-segment replacement to avoid replacing characters inside numbers.
     */
    private function buildPathMask(string $pathNormal, string $coin, mixed $bip44Coin): string
    {
        $segments = explode('/', $pathNormal);
        $pathChange = $this->params['pathChange'];
        $pathAccount = $this->params['pathAccount'];

        foreach ($segments as &$segment) {
            $hardened = str_ends_with($segment, "'");
            $base = $hardened ? substr($segment, 0, -1) : $segment;

            $base = match ($base) {
                'x' => '%d',
                'c' => $this->resolveCoinVariable($coin, $bip44Coin),
                'v' => (string)$pathChange,
                'a' => (string)$pathAccount,
                default => $base,
            };

            $segment = $hardened ? $base . "'" : $base;
        }
        unset($segment);

        return implode('/', $segments);
    }

    private function resolveCoinVariable(string $coin, mixed $bip44Coin): string
    {
        if (is_int($bip44Coin)) {
            return (string)$bip44Coin;
        }
        throw new \RuntimeException("'c' is present in path but Bip44 coin type is undefined for $coin");
    }

    /**
     * Replacement for HierarchicalKey::derivePath() that supports absolute paths.
     */
    private function derivePath(HierarchicalKey $key, string $path): HierarchicalKey
    {
        $sequences = new HierarchicalKeySequence();
        $isAbsolute = in_array($path[0] ?? '', ['m', 'M'], true);
        $parts = $isAbsolute
            ? $sequences->decodeAbsolute($path)[1]
            : $sequences->decodeRelative($path);
        $numParts = count($parts);

        for ($i = 0; $i < $numParts; $i++) {
            try {
                $key = $key->deriveChild($parts[$i]);
            } catch (InvalidDerivationException $e) {
                $message = ($i === $numParts - 1)
                    ? $e->getMessage()
                    : 'Invalid derivation for non-terminal index: cannot use this path!';
                throw new InvalidDerivationException($message);
            }
        }

        return $key;
    }

    private function deriveKeyWorker(
        string $coin,
        string $symbol,
        NetworkInterface $network,
        array &$addrs,
        HierarchicalKey $key,
        string $keyType,
        ?int $index,
        string $path,
    ): void {
        if (!$this->networkSupportsKeyType($network, $keyType, $coin)) {
            throw new \RuntimeException("$keyType extended keys are not supported for $coin");
        }

        if (!method_exists($key, 'getPublicKey')) {
            throw new \RuntimeException('multisig keys not supported');
        }

        $address = strtolower($symbol) === 'eth'
            ? $this->getEthereumAddress($key->getPublicKey())
            : $this->address($key, $network);

        if (strtolower($symbol) === 'bch' && $this->params['bchFormat'] !== 'legacy') {
            $address = CashAddress::old2new($address);
        }

        $xprv = $key->isPrivate() ? $this->toExtendedKey($coin, $key, $network, $keyType) : null;
        $privWif = $key->isPrivate() ? $this->serializePrivKey($symbol, $network, $key->getPrivateKey()) : null;
        $pubkey = $key->getPublicKey()->getHex();
        $pubkeyhash = $key->getPublicKey()->getPubKeyHash()->getHex();
        $xpub = $this->toExtendedKey($coin, $key->withoutPrivateKey(), $network, $keyType);

        $addrs[] = [
            'xprv' => $xprv,
            'privkey' => $privWif,
            'pubkey' => $pubkey,
            'pubkeyhash' => $pubkeyhash,
            'xpub' => $xpub,
            'address' => $address,
            'index' => $index,
            'path' => $path,
        ];
    }

    private function serializePrivKey(string $symbol, NetworkInterface $network, mixed $key): string
    {
        return strtolower($symbol) === 'eth'
            ? '0x' . $key->getHex()
            : $key->toWif($network);
    }

    private function address(HierarchicalKey $key, NetworkInterface $network): string
    {
        $addrCreator = new AddressCreator();
        return $key->getAddress($addrCreator)->getAddress($network);
    }

    private function getKeyTypeFromCoinAndKey(string $coin, string $key): string
    {
        $prefix = substr($key, 0, 4);
        $s = new RawExtendedKeySerializer(Bitcoin::getEcAdapter());
        $rkp = $s->fromParser(new Parser(Base58::decodeCheck($key)));
        $keyPrefix = '0x' . $rkp->getPrefix();

        $ext = $this->getExtendedPrefixes($coin);
        foreach ($ext as $kt => $info) {
            if (!is_array($info)) {
                continue;
            }
            if ($keyPrefix === strtolower($info['public'] ?? '')) {
                return $kt[0];
            }
            if ($keyPrefix === strtolower($info['private'] ?? '')) {
                return $kt[0];
            }
        }
        throw new \RuntimeException("Keytype not found for $coin/$prefix");
    }

    private function getSerializer(string $coin, NetworkInterface $network, string $keyType): Base58ExtendedKeySerializer
    {
        $adapter = Bitcoin::getEcAdapter();
        $prefix = $this->getScriptPrefixForKeyType($coin, $keyType);
        $config = new GlobalPrefixConfig([new NetworkConfig($network, [$prefix])]);

        return new Base58ExtendedKeySerializer(new ExtendedKeySerializer($adapter, $config));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getSymbolAndNetwork(?string $coin = null): array
    {
        $coin = $coin ?? $this->params['coin'];
        [$symbol, $network] = explode('-', $this->coinToChain($coin));
        return [strtoupper($symbol), strtolower($network)];
    }

    private function normalizeCoin(string $coin): string
    {
        [$symbol, $net] = $this->getSymbolAndNetwork($coin);
        $suffix = $net === 'main' ? '' : '-' . $net;
        return $symbol . $suffix;
    }

    private function getNetworkParams(?string $coin = null): array
    {
        [$symbol, $net] = $this->getSymbolAndNetwork($coin);
        return CoinParams::get_coin_network($symbol, $net);
    }

    private function getExtendedPrefixes(string $coin): array
    {
        $nparams = $this->getNetworkParams($coin);
        $altExtended = $this->params['altExtended'] ?? null;

        if ($altExtended) {
            $val = $nparams['prefixes']['extended']['alternates'][$altExtended] ?? null;
            if (!$val) {
                throw new \InvalidArgumentException('Invalid value for altExtended. Check coin type');
            }
        } else {
            $val = $nparams['prefixes']['extended'] ?? [];
            unset($val['alternates']);
        }

        $val = $val ?: [];
        foreach ($val as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            if (empty($v['public']) || empty($v['private'])) {
                unset($val[$k]);
            }
        }
        return $val;
    }

    private function networkSupportsKeyType(NetworkInterface $network, string $keyType, string $coin): bool
    {
        if ($keyType === 'z') {
            try {
                $network->getSegwitBech32Prefix();
            } catch (\Exception) {
                return false;
            }
        }

        $extPrefixes = $this->getExtendedPrefixes($coin);
        $mcr = new MultiCoinRegistry($extPrefixes);
        return (bool)$mcr->prefixBytesByKeyType($keyType);
    }

    private function getScriptDataFactoryForKeyType(string $keyType): mixed
    {
        $helper = new KeyToScriptHelper(Bitcoin::getEcAdapter());

        $addrType = $this->params['addrType'];
        switch ($addrType) {
            case 'legacy':
                return $helper->getP2pkhFactory();
            case 'p2sh-segwit':
                return $helper->getP2shFactory($helper->getP2wpkhFactory());
            case 'bech32':
                return $helper->getP2wpkhFactory();
            case 'auto':
                break;
            default:
                throw new \InvalidArgumentException("Invalid value for addrType: $addrType");
        }

        return match ($keyType) {
            'x' => $helper->getP2pkhFactory(),
            'X' => $helper->getP2shFactory($helper->getP2pkhFactory()),
            'y' => $helper->getP2shFactory($helper->getP2wpkhFactory()),
            'Y' => $helper->getP2shP2wshFactory($helper->getP2pkhFactory()),
            'z' => $helper->getP2wpkhFactory(),
            'Z' => $helper->getP2wshFactory($helper->getP2pkhFactory()),
            default => throw new \InvalidArgumentException("Unknown key type: $keyType"),
        };
    }

    private function getScriptPrefixForKeyType(string $coin, string $keyType): mixed
    {
        $addrType = $this->params['addrType'];
        $adapter = Bitcoin::getEcAdapter();
        $slip132 = new Slip132(new KeyToScriptHelper($adapter));
        $extPrefixes = $this->getExtendedPrefixes($coin);

        if ($addrType !== 'auto') {
            $extPrefixes['xpub'] = $extPrefixes[$keyType . 'pub'];
            $extPrefixes['ypub'] = $extPrefixes[$keyType . 'pub'];
            $extPrefixes['zpub'] = $extPrefixes[$keyType . 'pub'];
        }
        $coinPrefixes = new MultiCoinRegistry($extPrefixes);

        switch ($addrType) {
            case 'legacy':
                return $slip132->p2pkh($coinPrefixes);
            case 'p2sh-segwit':
                return $slip132->p2shP2wpkh($coinPrefixes);
            case 'bech32':
                return $slip132->p2wpkh($coinPrefixes);
            case 'auto':
                break;
            default:
                throw new \InvalidArgumentException("Invalid value for addrType: $addrType");
        }

        return match ($keyType) {
            'x' => $slip132->p2pkh($coinPrefixes),
            'y' => $slip132->p2shP2wpkh($coinPrefixes),
            'z' => $slip132->p2wpkh($coinPrefixes),
            default => throw new \InvalidArgumentException("Unknown key type: $keyType"),
        };
    }

    private function toExtendedKey(string $coin, HierarchicalKey $key, NetworkInterface $network, string $keyType): string
    {
        $serializer = $this->getSerializer($coin, $network, $keyType);
        return $serializer->serialize($network, $key);
    }

    private function fromExtended(string $coin, string $extendedKey, NetworkInterface $network, string $keyType): HierarchicalKey
    {
        $serializer = $this->getSerializer($coin, $network, $keyType);
        return $serializer->parse($network, $extendedKey);
    }

    public function mnemonicToKey(string $coin, string $mnemonic, string $keyType, ?string $password = ''): string
    {
        $network = NetworkCoinFactory::getNetworkCoinInstance($coin);
        Bitcoin::setNetwork($network);

        $seedGenerator = new Bip39SeedGenerator();
        $password = $password ?? '';
        $seed = $seedGenerator->getSeed($mnemonic, $password);

        $scriptFactory = $this->getScriptDataFactoryForKeyType($keyType);
        $bip32 = $this->hkf->fromEntropy($seed, $scriptFactory);

        return $this->toExtendedKey($coin, $bip32, $network, $keyType);
    }

    public function coinToChain(string $coin): string
    {
        return str_contains($coin, '-') ? $coin : "$coin-main";
    }

    public function getCoinBip44(string $coin): mixed
    {
        $map = CoinParams::get_all_coins();
        [$symbol, $net] = explode('-', $this->coinToChain($coin));
        return $map[strtoupper($symbol)][$net]['prefixes']['bip44'] ?? null;
    }

    public function getCoinBip44ExtKeyPathPurpose(string $coin, int $purpose): ?string
    {
        $bip44 = $this->getCoinBip44($coin);
        return is_int($bip44) ? sprintf("m/%s'/%d'/0'/0", $purpose, $bip44) : null;
    }

    public function getBip32PurposeByKeyType(string $keyType): int
    {
        return match ($keyType) {
            'x' => 44,
            'y' => 49,
            'z' => 84,
            'Y', 'Z' => 141,
            default => throw new \InvalidArgumentException("Unknown key type: $keyType"),
        };
    }

    private function getEthereumAddress(PublicKeyInterface $publicKey): string
    {
        static $pubkeySerializer = null;
        static $pointSerializer = null;

        if (!$pubkeySerializer) {
            $adapter = EcAdapterFactory::getPhpEcc(Bitcoin::getMath(), Bitcoin::getGenerator());
            $pubkeySerializer = new PublicKeySerializer($adapter);
            $pointSerializer = new UncompressedPointSerializer(EccFactory::getAdapter());
        }

        $pubKey = $pubkeySerializer->parse($publicKey->getBuffer());
        $point = $pubKey->getPoint();
        $upk = $pointSerializer->serialize($point);
        $upk = hex2bin(substr($upk, 2));

        $keccak = Keccak::hash($upk, 256);
        $ethAddressLower = strtolower(substr($keccak, -40));

        $hash = Keccak::hash($ethAddressLower, 256);
        $ethAddress = '';
        for ($i = 0; $i < 40; $i++) {
            $char = $ethAddressLower[$i];

            if (ctype_digit($char)) {
                $ethAddress .= $char;
            } elseif ($hash[$i] >= '0' && $hash[$i] <= '7') {
                $ethAddress .= strtolower($char);
            } else {
                $ethAddress .= strtoupper($char);
            }
        }

        return '0x' . $ethAddress;
    }
}

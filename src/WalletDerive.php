<?php


namespace Derive;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\EcAdapter\EcAdapterFactory;
use BitWasp\Bitcoin\Crypto\EcAdapter\Impl\PhpEcc\Serializer\Key\PublicKeySerializer;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Exceptions\InvalidDerivationException;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeySequence;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Key\KeyToScript\KeyToScriptHelper;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\RawExtendedKeySerializer;
use BitWasp\Buffertools\Parser;
use CoinParams\CoinParams;
use kornrunner\Keccak;
use Mdanter\Ecc\EccFactory;
use Mdanter\Ecc\Serializer\Point\UncompressedPointSerializer;
use Derive\Utils\CashAddress;
use Derive\Utils\MultiCoinRegistry;
use Derive\Utils\NetworkCoinFactory;
//use Derive\Exception;

class WalletDerive
{
    protected $params;
    protected HierarchicalKeyFactory $hkf;

    public function __construct($params)
    {
        $this->params = $params;
        $this->hkf = new HierarchicalKeyFactory();
    }

    private function getParams()
    {
        return $this->params;
    }

    /* Derives child keys/addresses for a given key.
     */
    public function derive_keys($key): array
    {
        $params = $this->getParams();
        return $this->derive_keys_worker($params, $key);
    }

    private function derive_keys_worker($params, $key)
    {
        $coin = $params['coin'];
        list($symbol) = explode('-', $coin);
        $addrs = array();

        $bip44_coin = $this->getCoinBip44($coin);  // bip44/slip-0044 coin identifier

        $networkCoinFactory = new NetworkCoinFactory();
        $network = $networkCoinFactory->getNetworkCoinInstance($coin);
        Bitcoin::setNetwork($network);
        $key_type = $this->getKeyTypeFromCoinAndKey($coin, $key);

        $master = $this->fromExtended($coin, $key, $network, $key_type);

        $start = $params['startindex'];
        $end = $params['startindex'] + $params['numderive'];

        /*
         *  ROOT PATH INCLUSION
         * */
        if ($params['includeroot']) {
            $this->derive_key_worker($coin, $symbol, $network, $addrs, $master, $key_type, null, 'm');
        }

//        MyLoggerDeL::getInstance()->log( "Deriving keys", MyLoggerDeL::info );
        $path_base = is_numeric($params['path'][0]) ? 'm/' . $params['path'] : $params['path'];

        // Allow paths to end with i or i'.
        // i' specifies that addresses should be hardened.
        $pparts = explode('/', $path_base);

        $iter_part = null;
        foreach ($pparts as $idx => $pp) {
            if ($pp[0] == 'x') {
                $iter_part = $idx;
            }
        }
        if (!$iter_part) {
//            $iter_part = count($pparts);
            $pparts[] = 'x';
        }
        $path_normal = implode('/', $pparts);
        $path_mask = str_replace('x', '%d', $path_normal);
        if (str_contains($path_mask, 'c')) {
            if (is_int($bip44_coin)) {
                $path_mask = str_replace('c', $bip44_coin, $path_mask);  // auto-insert bip44 coin-type if requested via 'c'.
            } else {
                throw new \Exception("'c' is present in path but Bip44 coin type is undefined for $coin");
            }
        }
        $path_mask = str_replace('v', @$params['path-change'], $path_mask);
        $path_mask = str_replace('a', @$params['path-account'], $path_mask);

        $period_start = time();
        for ($i = $start; $i < $end; $i++) {
            $path = sprintf($path_mask, $i);

            // $key = $master->derivePath($path);
            $key = $this->derive_path($master, $path);
            $this->derive_key_worker($coin, $symbol, $network, $addrs, $key, $key_type, $i, $path);

            if (time() - $period_start > 10) $period_start = time();
        }

        return $addrs;
    }

    // This function is a replacement for HierarchicalKey::derivePath()
    // since that function now accepts only relative paths.
    //
    // This function is exactly the same except it will detect if first
    // char is 'm' or 'M' and then will call decodeAbsolute() instead.
    private function derive_path($key, string $path): HierarchicalKey
    {
        $sequences = new HierarchicalKeySequence();
        $is_abs = in_array(@$path[0], ['m', 'M']);
        $parts = $is_abs ? @$sequences->decodeAbsolute($path)[1] : $sequences->decodeRelative($path);
        $numParts = count($parts);

        for ($i = 0; $i < $numParts; $i++) {
            try {
                $key = $key->deriveChild($parts[$i]);
            } catch (InvalidDerivationException $e) {
                if ($i === $numParts - 1) {
                    throw new InvalidDerivationException($e->getMessage());
                } else {
                    throw new InvalidDerivationException("Invalid derivation for non-terminal index: cannot use this path!");
                }
            }
        }

        return $key;
    }

    private function derive_key_worker($coin, $symbol, $network, &$addrs, $key, $key_type, $index, $path)
    {

        if (!$this->networkSupportsKeyType($network, $key_type, $coin)) {
            throw new \Exception("$key_type extended keys are not supported for $coin");
        }

        $params = $this->getParams();
        if (method_exists($key, 'getPublicKey')) {
            $address = strtolower($symbol) == 'eth' ?
                $address = $this->getEthereumAddress($key->getPublicKey()) :
                $this->address($key, $network);
            // (new PayToPubKeyHashAddress($key->getPublicKey()->getPubKeyHash()))->getAddress();

            if (strtolower($symbol) == 'bch' && $params['bch-format'] != 'legacy') {
                $address = CashAddress::old2new($address);
            }

            $xprv = $key->isPrivate() ? $this->toExtendedKey($coin, $key, $network, $key_type) : null;
            $priv_wif = $key->isPrivate() ? $this->serializePrivKey($symbol, $network, $key->getPrivateKey()) : null;
            $pubkey = $key->getPublicKey()->getHex();
            $pubkeyhash = $key->getPublicKey()->getPubKeyHash()->getHex();
            $xpub = $this->toExtendedKey($coin, $key->withoutPrivateKey(), $network, $key_type);
        } else {
            throw new \Exception("multisig keys not supported");
        }

        $addrs[] = array('xprv' => $xprv,
            'privkey' => $priv_wif,
            'pubkey' => $pubkey,
            'pubkeyhash' => $pubkeyhash,
            'xpub' => $xpub,
            'address' => $address,
            'index' => $index,
            'path' => $path);
    }

    function serializePrivKey($symbol, $network, $key)
    {
        $hex = strtolower($symbol) == 'eth';
        return $hex ? '0x' . $key->getHex() : $key->toWif($network);
    }


    private function address($key, $network)
    {
        $addrCreator = new AddressCreator();
        return $key->getAddress($addrCreator)->getAddress($network);
    }

    /*
     * Determines key type (x,y,Y,z,Z) based on coin/network and a key.
     */
    private function getKeyTypeFromCoinAndKey($coin, $key)
    {
//        $nparams = $this->getNetworkParams($coin);
        $prefix = substr($key, 0, 4);

        // Parse the key to obtain prefix bytes.
        $s = new RawExtendedKeySerializer(Bitcoin::getEcAdapter());
        $rkp = $s->fromParser(new Parser(Base58::decodeCheck($key)));
        $key_prefix = '0x' . $rkp->getPrefix();

        $ext = $this->getExtendedPrefixes($coin);
        foreach ($ext as $kt => $info) {
            if (!is_array($info)) {
                continue;
            }
            if ($key_prefix == strtolower(@$info['public'])) {
                return $kt[0];
            }
            if ($key_prefix == strtolower(@$info['private'])) {
                return $kt[0];
            }
        }
        throw new \Exception("Keytype not found for $coin/$prefix");
    }

    private function getKeyTypeFromParams()
    {
        $params = $this->getParams();
        return $params['key-type'];
    }

    private function getSerializer($coin, $network, $key_type)
    {
        $adapter = Bitcoin::getEcAdapter();

        $prefix = $this->getScriptPrefixForKeyType($coin, $key_type);
        $config = new GlobalPrefixConfig([new NetworkConfig($network, [$prefix]),]);

        $serializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($adapter, $config));
        return $serializer;
    }

    private function getSymbolAndNetwork($coin = null)
    {
        if (!$coin) {
            $params = $this->getParams();
            $coin = $params['coin'];
        }
        list($symbol, $network) = explode('-', $this->coinToChain($coin));
        // normalize values.
        return [strtoupper($symbol), strtolower($network)];
    }

    private function normalizeCoin($coin): string
    {
        list($symbol, $net) = $this->getSymbolAndNetwork($coin);
        $suffix = $net == 'main' ? '' : '-' . $net;
        return "$symbol" . $suffix;
    }

    private function getNetworkParams($coin = null)
    {
        list($symbol, $net) = $this->getSymbolAndNetwork($coin);
        return coinparams::get_coin_network($symbol, $net);
    }

    private function getExtendedPrefixes($coin)
    {
        $params = $this->getParams();
        $nparams = $this->getNetworkParams($coin);
        if (@$params['alt-extended']) {
            $ext = @$params['alt-extended'];
            $val = @$nparams['prefixes']['extended']['alternates'][$ext];
            if (!$val) {
                throw new \Exception("Invalid value for --alt-extended.  Check coin type");
            }
        } else {
            $val = @$nparams['prefixes']['extended'];
            unset($val['alternates']);
        }
        $val = $val ?: [];
        // ensure no entries with empty values.
        foreach ($val as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            if (!@$v['public'] || !@$v['private']) {
                unset($val[$k]);
            }
        }
        return $val;
    }

    private function networkSupportsKeyType($network, $key_type, $coin): bool
    {
        if ($key_type == 'z') {
            try {
                $network->getSegwitBech32Prefix();
            } catch (\Exception $e) {
                return false;
            }
        }

        $ext_prefixes = $this->getExtendedPrefixes($coin);
        $mcr = new MultiCoinRegistry($ext_prefixes);
        return (bool)$mcr->prefixBytesByKeyType($key_type);
    }

    // key_type is one of x,y,Y,z,Z
    private function getScriptDataFactoryForKeyType($key_type)
    {
        $helper = new KeyToScriptHelper(Bitcoin::getEcAdapter());

        $params = $this->getParams();
        $addr_type = $params['addr-type'];
        switch ($addr_type) {
            case 'legacy':
                return $helper->getP2pkhFactory();
            case 'p2sh-segwit':
                return $helper->getP2shFactory($helper->getP2wpkhFactory());
            case 'bech32':
                return $helper->getP2wpkhFactory();
            case 'auto':
                break;  // use automatic detection based on key_type
            default:
                throw new \Exception('Invalid value for addr_type');
        }

        // note: these calls are adapted from bitwasp slip132.php
        switch ($key_type) {
            case 'x':
                $factory = $helper->getP2pkhFactory();
                break;
            case 'X':
                $factory = $helper->getP2shFactory($helper->getP2pkhFactory());
                break;  // also xpub.  this case won't work.
            case 'y':
                $factory = $helper->getP2shFactory($helper->getP2wpkhFactory());
                break;
            case 'Y':
                $factory = $helper->getP2shP2wshFactory($helper->getP2pkhFactory());
                break;
            case 'z':
                $factory = $helper->getP2wpkhFactory();
                break;
            case 'Z':
                $factory = $helper->getP2wshFactory($helper->getP2pkhFactory());
                break;
            default:
                throw new \Exception("Unknown key type: $key_type");
        }
        return $factory;
    }

    // key_type is one of x,y,Y,z,Z
    private function getScriptPrefixForKeyType($coin, $key_type)
    {
//        list($symbol, $net) = $this->getSymbolAndNetwork($coin);

        $params = $this->getParams();
        $addr_type = $params['addr-type'];

        $adapter = Bitcoin::getEcAdapter();
        $slip132 = new Slip132(new KeyToScriptHelper($adapter));
        $ext_prefixes = $this->getExtendedPrefixes($coin);

        if ($addr_type != 'auto') {
            $ext_prefixes['xpub'] = $ext_prefixes[$key_type . 'pub'];
            $ext_prefixes['ypub'] = $ext_prefixes[$key_type . 'pub'];
            $ext_prefixes['zpub'] = $ext_prefixes[$key_type . 'pub'];
        }
        $coinPrefixes = new MultiCoinRegistry($ext_prefixes);

        switch ($addr_type) {
            case 'legacy':
                return $slip132->p2pkh($coinPrefixes);
            case 'p2sh-segwit':
                return $slip132->p2shP2wpkh($coinPrefixes);
            case 'bech32':
                return $slip132->p2wpkh($coinPrefixes);
            case 'auto':
                break;  // use automatic detection based on key_type
            default:
                throw new \Exception('Invalid value for addr_type');
        }

        return match ($key_type) {
            'x' => $slip132->p2pkh($coinPrefixes),
            'y' => $slip132->p2shP2wpkh($coinPrefixes),
            'z' => $slip132->p2wpkh($coinPrefixes),
            default => throw new \Exception("Unknown key type: $key_type"),
        };
    }

    private function toExtendedKey($coin, $key, $network, $key_type)
    {
        $serializer = $this->getSerializer($coin, $network, $key_type);
        return $serializer->serialize($network, $key);
    }

    private function fromExtended($coin, $extendedKey, $network, $key_type)
    {
        $serializer = $this->getSerializer($coin, $network, $key_type);
        return $serializer->parse($network, $extendedKey);
    }

    // converts a bip39 mnemonic string with optional password to an xprv key (string).
    public function mnemonicToKey($coin, $mnemonic, $key_type, $password = '')
    {
        $networkCoinFactory = new NetworkCoinFactory();
        $network = $networkCoinFactory->getNetworkCoinInstance($coin);
        Bitcoin::setNetwork($network);

        $seedGenerator = new Bip39SeedGenerator();

        // Derive a seed from mnemonic/password
        $password = $password === null ? '' : $password;
        $seed = $seedGenerator->getSeed($mnemonic, $password);

        $scriptFactory = $this->getScriptDataFactoryForKeyType($key_type);

        $bip32 = $this->hkf->fromEntropy($seed, $scriptFactory);
        return $this->toExtendedKey($coin, $bip32, $network, $key_type);
    }

    protected function genRandomSeed($password = null)
    {
        $params = $this->getParams();
        $num_bytes = (int)($params['gen-words'] / 0.75);

        // generate random mnemonic
        $random = new Random();
        $bip39 = MnemonicFactory::bip39();
        $entropy = $random->bytes($num_bytes);
        $mnemonic = $bip39->entropyToMnemonic($entropy);

        // generate seed and master priv key from mnemonic
        $seedGenerator = new Bip39SeedGenerator();
        $pw = $password == null ? '' : $password;
        $seed = $seedGenerator->getSeed($mnemonic, $pw);

        $data = [
            'seed' => $seed,
            'mnemonic' => $mnemonic,
        ];

        return $data;
    }

    protected function genKeysFromSeed($coin, $seedinfo)
    {
        $networkCoinFactory = new NetworkCoinFactory();
        $network = $networkCoinFactory->getNetworkCoinInstance($coin);
        Bitcoin::setNetwork($network);

        // type   purpose
        $key_types = ['x' => 44,
            'y' => 49,
            'z' => 84,
//                      'Y'  => ??,    // multisig
//                      'Z'  => ??,    // multisig
        ];
        $keys = [];

        $rows = [];
        foreach ($key_types as $key_type => $purpose) {
            if (!$this->networkSupportsKeyType($network, $key_type, $coin)) {
                // $data[$key_type] = null;
                continue;
            }
            $row = ['coin' => $this->normalizeCoin($coin),
                'seed' => $seedinfo['seed']->getHex(),
                'mnemonic' => $seedinfo['mnemonic']
            ];

            $k = $key_type;
            $pf = '';

            $scriptFactory = $this->getScriptDataFactoryForKeyType($key_type);  // xpub

            $xkey = $this->hkf->fromEntropy($seedinfo['seed'], $scriptFactory);
            $masterkey = $this->toExtendedKey($coin, $xkey, $network, $key_type);
            $row[$pf . 'root-key'] = $masterkey;

            // determine bip32 path for ext keys, which requires a bip44 ID for coin.
            $bip32path = $this->getCoinBip44ExtKeyPathPurpose($coin, $purpose);
            if ($bip32path) {
                // derive extended priv/pub keys.
                // $prv = $xkey->derivePath($bip32path);
                $prv = $this->derive_path($xkey, $bip32path);
                $pub = $prv->withoutPrivateKey();
                $row[$pf . 'path'] = $bip32path;
                $row['xprv'] = $this->toExtendedKey($coin, $prv, $network, $key_type);
                $row['xpub'] = $this->toExtendedKey($coin, $pub, $network, $key_type);
                $row['comment'] = null;
            } else {
                $row[$pf . 'path'] = null;
                $row['xprv'] = null;
                $row['xpub'] = null;
                $row['comment'] = "Bip44 ID is missing for this coin, so extended keys not derived.";
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function coinToChain(string $coin): string
    {
        return str_contains($coin, '-') ? $coin : "$coin-main";
    }

    public function getCoinBip44($coin)
    {
        $map = CoinParams::get_all_coins();
        list($symbol, $net) = explode('-', $this->coinToChain($coin));
        return @$map[strtoupper($symbol)][$net]['prefixes']['bip44'];
    }

    public function getCoinBip44ExtKeyPathPurpose($coin, $purpose): ?string
    {
        $bip44 = $this->getCoinBip44($coin);
        return is_int($bip44) ? sprintf("m/%s'/%d'/0'/0", $purpose, $bip44) : null;
    }

    public function getBip32PurposeByKeyType(int $key_type): int
    {
        return ['x' => 44,
            'y' => 49,
            'z' => 84,
            'Y' => 141,
            'Z' => 141,
        ][$key_type];
    }

    public function getCoinBip44ExtKeyPathPurposeByKeyType($coin, $key_type): ?string
    {
        $purpose = $this->getBip32PurposeByKeyType($key_type);
        return $this->getCoinBip44ExtKeyPathPurpose($coin, $purpose);
    }

    private function getEthereumAddress(PublicKeyInterface $publicKey): string
    {
        static $pubkey_serializer = null;
        static $point_serializer = null;
        if (!$pubkey_serializer) {
            $adapter = EcAdapterFactory::getPhpEcc(Bitcoin::getMath(), Bitcoin::getGenerator());
            $pubkey_serializer = new PublicKeySerializer($adapter);
            $point_serializer = new UncompressedPointSerializer(EccFactory::getAdapter());
        }

        $pubKey = $pubkey_serializer->parse($publicKey->getBuffer());
        $point = $pubKey->getPoint();
        $upk = $point_serializer->serialize($point);
        $upk = hex2bin(substr($upk, 2));

        $keccak = Keccak::hash($upk, 256);
        $eth_address_lower = strtolower(substr($keccak, -40));

        $hash = Keccak::hash($eth_address_lower, 256);
        $eth_address = '';
        for ($i = 0; $i < 40; $i++) {
            // the nth letter should be uppercase if the nth digit of casemap is 1
            $char = substr($eth_address_lower, $i, 1);

            if (ctype_digit($char))
                $eth_address .= $char;
            else if ('0' <= $hash[$i] && $hash[$i] <= '7')
                $eth_address .= strtolower($char);
            else
                $eth_address .= strtoupper($char);
        }

        return '0x' . $eth_address;
    }
}
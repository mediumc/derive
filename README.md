Library for obtaining bip32 addresses and private keys. Completely cut out CLI interaction and removed unnecessary functions and classes.

## Usage
Simple example of using the library

    $result = (new DeriveWrapper())->derive([  
	    'key' => 'xpub...dwUR',
	    'numderive' => 1
    ]);
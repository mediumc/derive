Library for obtaining bip32 addresses and private keys. Completely cut out CLI interaction and removed unnecessary functions and classes.

## Usage
Simple example of using the library

    $result = (new DeriveWrapper())->derive([  
	    'key' => 'xpub...dwUR',
	    'numderive' => 1
    ]);

## Result
The response always comes in JSON and has parameters ok, data - in case of successful completion, or ok, message - in case of an error.

<?php

namespace Netpromotion\DataSigner\Hmac;

use Netpromotion\DataSigner\DataSignerInterface;
use Netpromotion\DataSigner\Exception\CorruptedDataException;
use Netpromotion\DataSigner\Exception\UntrustedDataException;
use Netpromotion\DataSigner\SignedDataInterface;
use Nunzion\Expect;
use PetrKnap\Php\Enum\Exception\EnumNotFoundException;

class DataSigner implements DataSignerInterface
{
    /**
     * @var HashAlgorithm
     */
    private $hashAlgorithm;

    /**
     * @var string
     */
    private $secret;

    /**
     * @var string|null
     */
    private $domain;

    /**
     * @param HashAlgorithm $hashAlgorithm
     * @param string $secret
     * @param string|null $domain
     */
    public function __construct(HashAlgorithm $hashAlgorithm, $secret, $domain = null)
    {
        Expect::that($secret)->isString()->isNotEmpty();
        if (null !== $domain) {
            Expect::that($domain)->isString()->isNotEmpty();
        }

        $this->hashAlgorithm = $hashAlgorithm;
        $this->secret = $secret;
        $this->domain = $domain;
    }

    /**
     * @inheritdoc
     */
    public function withDomain($name)
    {
        if (null !== $name) {
            Expect::that($name)->isString()->isNotEmpty();
        }

        return new static($this->hashAlgorithm, $this->secret, $name);
    }

    /**
     * @internal public for test purpose only
     * @param HashAlgorithm $hashAlgorithm
     * @param string $secret
     * @param string $serializedData
     * @return mixed
     */
    public static function generateSignature(HashAlgorithm $hashAlgorithm, $secret, $serializedData)
    {
        Expect::that($secret)->isString()->isNotEmpty();
        Expect::that($serializedData)->isString()->isNotEmpty();

        return hash_hmac($hashAlgorithm, $serializedData, $secret, true);
    }

    /**
     * @internal public for test purpose only
     * @param HashAlgorithm $hashAlgorithm
     * @param string $secret
     * @param string $serializedData
     * @param mixed $signature
     * @return bool
     */
    public static function checkSignature(HashAlgorithm $hashAlgorithm, $secret, $serializedData, $signature)
    {
        Expect::that($signature)->isNotNull();

        return static::generateSignature($hashAlgorithm, $secret, $serializedData) === $signature;
    }

    /**
     * @inheritdoc
     */
    public function signData($data)
    {
        return new SignedData($data, $this->hashAlgorithm, static::generateSignature(
            $this->hashAlgorithm,
            $this->secret . $this->domain,
            serialize($data)
        ));
    }

    /**
     * @inheritdoc
     */
    public function getData($dataOrDataAsString)
    {
        if ($dataOrDataAsString instanceof SignedDataInterface) {
            Expect::that($dataOrDataAsString)->isInstanceOf(SignedData::class);
            $signedDataAsString = json_encode($dataOrDataAsString);
        } else {
            Expect::that($dataOrDataAsString)->isString()->isNotEmpty();
            $signedDataAsString = $dataOrDataAsString;
        }

        $decoded = json_decode($signedDataAsString, true, 2);

        if (null === $decoded) {
            throw new CorruptedDataException('json_decode failed', new \Exception(
                json_last_error_msg(),
                json_last_error()
            ));
        }

        $serializedData = $decoded[SignedData::JSON_SERIALIZED_DATA];
        try {
            $hashAlgorithm = HashAlgorithm::getEnumByValue($decoded[SignedData::JSON_ALGORITHM]);
        } catch (EnumNotFoundException $exception) {
            throw new CorruptedDataException('Unknown HashAlgorithm', $exception);
        }
        $signature = base64_decode($decoded[SignedData::JSON_B64_SIGNATURE]);

        if (!static::checkSignature($hashAlgorithm, $this->secret . $this->domain, $serializedData, $signature)) {
            throw new UntrustedDataException($serializedData);
        }

        return unserialize($serializedData);
    }
}

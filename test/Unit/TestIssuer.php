<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Crypto\Canonicalizer;

/** A throwaway company issuer: an Ed25519 key, its DID document, and an eddsa-jcs-2022 signer. */
final class TestIssuer
{
    public readonly string $vm;

    /** @var array<string,mixed> */
    public readonly array $didDoc;

    private readonly string $secretKey;

    public function __construct(public readonly string $issuer = 'did:web:api.agreely.ca:c:acme')
    {
        $this->vm = $issuer . '#kms-1';
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->didDoc = [
            '@context' => ['https://www.w3.org/ns/did/v1'],
            'id' => $issuer,
            'verificationMethod' => [[
                'id' => $this->vm,
                'type' => 'Multikey',
                'controller' => $issuer,
                'publicKeyMultibase' => 'z' . self::base58("\xed\x01" . sodium_crypto_sign_publickey($pair)),
            ]],
        ];
    }

    /** @return callable(string): ?array<string,mixed> */
    public function resolver(): callable
    {
        $issuer = $this->issuer;
        $doc = $this->didDoc;
        return static fn (string $did): ?array => $did === $issuer ? $doc : null;
    }

    /**
     * The DataIntegrityProof over JCS(`$body`).
     *
     * @return array<string,string>
     */
    public function proofOver(mixed $body): array
    {
        $signature = sodium_crypto_sign_detached((new Canonicalizer())->encode($body), $this->secretKey);
        return [
            'type' => 'DataIntegrityProof',
            'cryptosuite' => 'eddsa-jcs-2022',
            'created' => '2026-01-15T12:00:00Z',
            'verificationMethod' => $this->vm,
            'proofPurpose' => 'assertionMethod',
            'proofValue' => 'z' . self::base58($signature),
        ];
    }

    private static function base58(string $bytes): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digits = [0];
        foreach (str_split($bytes) as $char) {
            $carry = ord($char);
            foreach ($digits as $i => $digit) {
                $carry += $digit << 8;
                $digits[$i] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }
        $out = '';
        foreach (str_split($bytes) as $char) {
            if ($char !== "\x00") {
                break;
            }
            $out .= '1';
        }
        foreach (array_reverse($digits) as $digit) {
            $out .= $alphabet[$digit];
        }
        return $out;
    }
}

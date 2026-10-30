<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Support;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Verify\ReceiptVerification;

/**
 * Shared by issuer-departure.json and live-dns.json: a receipt verified against a resolver whose issuer document a case
 * may patch, a chain answering by selector, and the plain GETs (a departure record, a DNS-over-HTTPS query) answered by
 * exact URL. An injected httpGet answers the body of a 2xx and null otherwise, as the option's contract says.
 */
final class LiveReads
{
    /** @var list<string> every GET URL, in order */
    public array $asked = [];

    /** The body an injected httpGet returns for one vector answer: a 2xx body, or null (an HTTP or network error). */
    public static function bodyOf(mixed $answer): ?string
    {
        if (!is_array($answer)) {
            return null;
        }
        $status = VectorCase::int($answer['status'] ?? null);
        if ($status < 200 || $status > 299) {
            return null;
        }
        return array_key_exists('text', $answer)
            ? VectorCase::str($answer['text'])
            : json_encode($answer['body'] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $c file, documents, issuerDocument, http, answers, options */
    public function verify(array $c): ReceiptVerification
    {
        $bundle = VectorCase::read(VectorCase::str($c['file']));
        $docs = VectorCase::read(VectorCase::str($c['documents']));
        /** @var array{issuer:string} $receipt */
        $receipt = $bundle['receipt'];
        $issuer = $receipt['issuer'];
        if (is_array($c['issuerDocument'] ?? null)) {
            $docs[$issuer] = VectorCase::map($c['issuerDocument']) + VectorCase::map($docs[$issuer]);
        }
        $http = VectorCase::map($c['http'] ?? []);
        $answers = $c['answers'] ?? null;
        $chain = RegistryAnswers::vectorChain($answers === null ? [] : VectorCase::map($answers));
        $opts = [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'httpGet' => function (string $url) use ($http): ?string {
                $this->asked[] = $url;
                return self::bodyOf($http[$url] ?? null);
            },
        ] + ($answers !== null ? ['rpcUrl' => 'https://rpc.test', 'chainId' => 84532, 'httpPost' => $chain->post(...)] : []);
        return Agreely::verifyReceipt($bundle, VectorCase::map($c['options'] ?? []) + $opts);
    }
}

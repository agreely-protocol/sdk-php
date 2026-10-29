<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Support;

/**
 * A JSON-RPC endpoint (the verifier's httpPost seam) answering each eth_call by its selector and eth_getLogs with the
 * `logs` answer. An answer is a callable: eth_call ones receive the first argument word (the ref of registry(bytes32)).
 * A missing answer, or one returning null, is a JSON-RPC error; one returning false is a transport failure.
 */
final class ChainStub
{
    private const SELECTORS = [
        '0x7ef50298' => 'registry',
        '0x6d6f398e' => 'rootOf',
        '0xdf78bce2' => 'keyOf',
        '0x25597a89' => 'companyKeyOf',
        '0x8181c629' => 'getCompanyDid',
    ];

    /** @var list<array{method:string, params:list<mixed>}> every request, in order */
    public array $calls = [];
    /** @var list<string> the ref of every registry(bytes32) read, in order */
    public array $refs = [];

    /** @param array<string,callable> $answers */
    public function __construct(private readonly array $answers)
    {
    }

    /** The httpPost seam: the JSON-RPC answer text, or null for a transport failure. */
    public function post(string $url, string $body): ?string
    {
        /** @var array{method?:string, params?:list<mixed>} $req */
        $req = (array) json_decode($body, true);
        $method = $req['method'] ?? '';
        $params = $req['params'] ?? [];
        $this->calls[] = ['method' => $method, 'params' => $params];
        $out = null;
        if ($method === 'eth_getLogs') {
            $answer = $this->answers['logs'] ?? null;
            $out = $answer === null ? null : $answer();
        } elseif ($method === 'eth_call') {
            $first = $params[0] ?? null;
            $data = is_array($first) && is_string($first['data'] ?? null) ? $first['data'] : '';
            $read = self::SELECTORS[substr($data, 0, 10)] ?? null;
            $arg = '0x' . substr($data, 10, 64);
            if ($read === 'registry') {
                $this->refs[] = $arg;
            }
            $answer = $read === null ? null : $this->answers[$read] ?? null;
            $out = $answer === null ? null : $answer($arg);
        }
        if ($out === false) {
            return null;
        }
        return (string) json_encode($out === null
            ? ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'no answer']]
            : ['jsonrpc' => '2.0', 'id' => 1, 'result' => $out]);
    }

    /**
     * The calls of one method.
     *
     * @return list<array{method:string, params:list<mixed>}>
     */
    public function callsOf(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c['method'] === $method));
    }
}

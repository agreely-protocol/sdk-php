<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\RegistryAnswers;
use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\TestCase;

/**
 * The REAL curl path of the on-chain checks, against a JSON-RPC server this test starts on a free local
 * port (PHP's built-in server). The other tests inject `httpPost` and so never see what curl sends: every
 * POST once went out with no body, and both anchor checks were dead.
 */
final class ReceiptVerifierCurlTest extends TestCase
{
    private const BLOCK = 50179548;

    /** @var resource|null */
    private $server = null;
    private string $dir = '';
    private int $port = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agreely-sdk-rpc-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $log = $this->dir . '/requests.log';
        // Answers registry(consentRef) with the root the request path names and its AnchorBatch log, both keys in force,
        // and hangs on /hang.
        file_put_contents($this->dir . '/router.php', '<?php
$body = (string) file_get_contents("php://input");
file_put_contents(' . var_export($log, true) . ', $body . "\n", FILE_APPEND);
$path = (string) parse_url((string) $_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/hang") { sleep(3); }
$req = json_decode($body, true);
$method = is_array($req) ? ($req["method"] ?? "") : "";
$word = fn (int $n): string => str_pad(dechex($n), 64, "0", STR_PAD_LEFT);
$root = substr($path, 1);
$selector = substr((string) ($req["params"][0]["data"] ?? ""), 0, 10);
$log = ' . var_export(RegistryAnswers::anchorBatchLog('0x' . str_repeat('0', 64), 3, self::BLOCK), true) . ';
$log["topics"][1] = "0x" . $root;
$result = match (true) {
    $method === "eth_getLogs" => [$log],
    $method === "eth_call" && $selector === "0x7ef50298" => "0x" . $root . $word(' . RegistryAnswers::A1V1_ANCHORED_AT . ')
        . $word(0) . $word(1) . $word(' . self::BLOCK . '),
    $method === "eth_call" => ' . var_export(RegistryAnswers::keyInForce(), true) . ',
    default => null,
};
header("Content-Type: application/json");
echo $method === "" ? "{\"jsonrpc\":\"2.0\",\"id\":1,\"error\":{\"code\":-32600,\"message\":\"no body\"}}"
    : json_encode(["jsonrpc" => "2.0", "id" => 1, "result" => $result]);
');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $this->dir . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.out', 'w'], 2 => ['file', $this->dir . '/server.out', 'w']],
            $pipes,
        );
        $this->assertIsResource($server);
        $this->server = $server;
        for ($i = 0; $i < 100; $i++) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        }
        $this->fail('the local JSON-RPC server did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (['router.php', 'requests.log', 'server.out'] as $file) {
            if (is_file($this->dir . '/' . $file)) {
                unlink($this->dir . '/' . $file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /** @param array<string,mixed> $opts */
    private function verify(string $path, array $opts = []): ReceiptVerification
    {
        /** @var array<string,mixed> $docs */
        $docs = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vectors/receipts/did-documents.json'), true);
        /** @var array<string,mixed> $bundle */
        $bundle = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vectors/receipts/a1v1.bundle.json'), true);
        return Agreely::verifyReceipt(RegistryAnswers::salted(RegistryAnswers::onReadableRegistry($bundle)), [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'rpcUrl' => "http://127.0.0.1:{$this->port}/{$path}",
            'chainId' => 84532,
        ] + $opts);
    }

    /** @return list<array<mixed>> */
    private function requests(): array
    {
        $lines = array_filter(explode("\n", (string) @file_get_contents($this->dir . '/requests.log')));
        return array_values(array_map(static fn (string $l): array => (array) json_decode($l, true), $lines));
    }

    public function testTheJsonRpcBodyReachesTheServerAndAContradictingAnchorFails(): void
    {
        $result = $this->verify(str_repeat('99', 32));
        $calls = $this->requests();
        $selectors = array_map(static function (array $call): string {
            $params = $call['params'] ?? null;
            $first = is_array($params) ? $params[0] ?? null : null;
            $data = is_array($first) ? $first['data'] ?? null : null;
            $method = $call['method'] ?? null;
            return (is_string($method) ? $method : '') . ' ' . (is_string($data) ? substr($data, 0, 10) : '');
        }, $calls);
        // The person's key is read first, then every consent ref against the registry.
        $this->assertSame('eth_call 0xdf78bce2', $selectors[0] ?? null);
        $this->assertContains('eth_call 0x7ef50298', $selectors);
        $this->assertSame('fail', $result->consentAnchor);
        $this->assertSame('failed', $result->overall);
    }

    public function testAGenuineAnchorUnderTheBundlesRootPasses(): void
    {
        /** @var array{merkleRoot:string} $bundle */
        $bundle = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vectors/receipts/a1v1.bundle.json'), true);
        $result = $this->verify(substr($bundle['merkleRoot'], 2));
        $this->assertSame('pass', $result->consentAnchor);
        $this->assertSame('verified', $result->overall);
        $this->assertSame(self::BLOCK, $result->consentAnchorRecords[0]['anchoredBlock'] ?? null);
        $this->assertSame('pass', $result->companyKeyRegistry);
        $this->assertSame(3, $result->consentAnchorCounts['anchorBatchRefs'] ?? null);
    }

    public function testAHangingRpcCannotHoldAVerificationPastTheDeadline(): void
    {
        $started = hrtime(true);
        $result = $this->verify('hang', ['rpcDeadlineMs' => 500]);
        $elapsedMs = intdiv(hrtime(true) - $started, 1_000_000);
        $this->assertLessThan(2500, $elapsedMs);
        $this->assertSame('unavailable', $result->consentAnchor);
        $this->assertSame('unavailable', $result->companyKeyRegistry);
        // A company key the registry did not confirm is never verified (the web verifier's keyUnconfirmed).
        $this->assertSame('unavailable', $result->overall);
        // The first call hangs to the deadline; every call after it is refused without a request.
        $this->assertCount(1, $this->requests());
    }
}

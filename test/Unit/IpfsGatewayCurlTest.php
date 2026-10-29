<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\VectorCase;
use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\TestCase;

/**
 * The REAL curl path of the IPFS copy, against gateways this test starts on a free local port (PHP's built-in server),
 * and a socket that accepts and never answers. The vector cases inject httpGet and never see an HTTP status, a header,
 * a redirect, a timeout or a body cut at one block.
 * TRAP: never PHP_CLI_SERVER_WORKERS here; proc_terminate stops only the master and the workers outlive the test.
 */
final class IpfsGatewayCurlTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    /** @var resource|null a listening socket nobody accepts on: the kernel completes the handshake, no answer ever comes */
    private $silent = null;
    private string $dir = '';
    private string $base = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agreely-sdk-ipfs-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $file = VectorCase::str(VectorCase::read('ipfs-gateways.json')['file']);
        $pinned = dirname(__DIR__, 2) . "/vectors/{$file}";
        // The first path segment names how the gateway answers; every request logs its path and Accept header.
        file_put_contents($this->dir . '/router.php', '<?php
$path = (string) parse_url((string) $_SERVER["REQUEST_URI"], PHP_URL_PATH);
file_put_contents(' . var_export($this->dir . '/requests.log', true) . ', $path . " " . ($_SERVER["HTTP_ACCEPT"] ?? "-") . "\n", FILE_APPEND);
$mode = explode("/", $path)[1] ?? "";
$pinned = (string) file_get_contents(' . var_export($pinned, true) . ');
if ($mode === "e502") { http_response_code(502); echo "bad gateway"; return; }
if ($mode === "big") { echo str_repeat(" ", 600000); return; }
if ($mode === "moved") { header("Location: /pinned/" . substr($path, strlen("/moved/"))); http_response_code(302); return; }
if ($mode === "pinned") { header("Content-Type: application/octet-stream"); echo $pinned; return; }
http_response_code(404);
');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $this->base = "http://127.0.0.1:{$port}";
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $this->dir . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.out', 'w'], 2 => ['file', $this->dir . '/server.out', 'w']],
            $pipes,
        );
        $this->assertIsResource($server);
        $this->server = $server;
        for ($i = 0; $i < 100; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        }
        $this->fail('the local gateway did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->silent)) {
            fclose($this->silent);
        }
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

    /**
     * @param list<string> $modes
     * @param array<string,mixed> $opts
     */
    private function verify(array $modes, array $opts = []): ReceiptVerification
    {
        $vector = VectorCase::read('ipfs-gateways.json');
        $docs = VectorCase::read('receipts/did-documents.json');
        return Agreely::verifyReceipt(VectorCase::read(VectorCase::str($vector['bundle'])), [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'verifyDisclosure' => true,
            'ipfsGateways' => array_map(fn (string $mode): string => "{$this->base}/{$mode}/", $modes),
        ] + $opts);
    }

    /** @return list<array{0:string, 1:string}> path and Accept header of every request, in order */
    private function requests(): array
    {
        $lines = array_filter(explode("\n", (string) @file_get_contents($this->dir . '/requests.log')));
        return array_values(array_map(static function (string $l): array {
            $parts = explode(' ', $l, 2);
            return [$parts[0], $parts[1] ?? ''];
        }, $lines));
    }

    private static function unavailableNote(ReceiptVerification $result): string
    {
        foreach ($result->notes as $note) {
            if (str_starts_with($note, 'disclosureCopy UNAVAILABLE')) {
                return $note;
            }
        }
        return '';
    }

    public function testTheFileServedOverHttpPassesAndNoAcceptJsonIsSent(): void
    {
        $result = $this->verify(['pinned']);
        $this->assertSame('pass', $result->disclosureCopy);
        $this->assertSame("{$this->base}/pinned/", $result->disclosureGateway);
        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertStringNotContainsString('application/json', $requests[0][1]);
    }

    public function testAnHttpErrorIsNamedByItsStatusAndTheNextGatewayIsAsked(): void
    {
        $result = $this->verify(['e502', 'pinned']);
        $this->assertSame('pass', $result->disclosureCopy);
        $this->assertSame("{$this->base}/pinned/", $result->disclosureGateway);
        $failed = $this->verify(['e502']);
        $this->assertSame('unavailable', $failed->disclosureCopy);
        $this->assertStringContainsString("{$this->base}/e502/ (HTTP 502)", self::unavailableNote($failed));
    }

    public function testABodyOverOneBlockIsCutAndNeverTheDocument(): void
    {
        $result = $this->verify(['big']);
        $this->assertSame('unavailable', $result->disclosureCopy);
        $this->assertStringContainsString('(a body over 262144 bytes)', self::unavailableNote($result));
        $this->assertSame('verified', $result->overall);
    }

    public function testARedirectIsFollowedAsABrowserFetchFollowsIt(): void
    {
        $result = $this->verify(['moved']);
        $this->assertSame('pass', $result->disclosureCopy);
        $this->assertSame("{$this->base}/moved/", $result->disclosureGateway);
    }

    public function testAGatewaySlowerThanTheTimeoutIsAbandonedForTheNext(): void
    {
        $started = hrtime(true);
        $silent = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($silent);
        $this->silent = $silent;
        $vector = VectorCase::read('ipfs-gateways.json');
        $docs = VectorCase::read('receipts/did-documents.json');
        $result = Agreely::verifyReceipt(VectorCase::read(VectorCase::str($vector['bundle'])), [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'verifyDisclosure' => true,
            'ipfsGateways' => ['http://' . stream_socket_get_name($silent, false) . '/ipfs/', "{$this->base}/pinned/"],
            'timeoutMs' => 500,
        ]);
        $this->assertLessThan(2500, intdiv(hrtime(true) - $started, 1_000_000));
        $this->assertSame('pass', $result->disclosureCopy);
        $this->assertSame("{$this->base}/pinned/", $result->disclosureGateway);
    }
}

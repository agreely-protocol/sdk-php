<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\LiveReads;
use Agreely\Sdk\Test\Support\RegistryAnswers;
use Agreely\Sdk\Test\Support\VectorCase;
use PHPUnit\Framework\TestCase;

/**
 * The REAL curl path of the live DNS read, against a DNS-over-HTTPS resolver this test starts on a free local port
 * (PHP's built-in server). Like Cloudflare's, it answers JSON only to `Accept: application/dns-json` and refuses the
 * `application/json` every other GET of this SDK sends with a 400.
 */
final class LiveDnsCurlTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $dir = '';
    private int $port = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agreely-sdk-doh-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $case = self::confirmedCase();
        $body = (string) LiveReads::bodyOf(array_values(VectorCase::map($case['http']))[0]);
        file_put_contents($this->dir . '/answer.json', $body);
        $log = $this->dir . '/requests.log';
        file_put_contents($this->dir . '/router.php', '<?php
$accept = (string) ($_SERVER["HTTP_ACCEPT"] ?? "");
file_put_contents(' . var_export($log, true) . ', $accept . " " . $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND);
if ($accept !== "application/dns-json") { http_response_code(400); return true; }
header("Content-Type: application/dns-json");
echo file_get_contents(' . var_export($this->dir . '/answer.json', true) . ');
return true;
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
        $this->fail('the local DNS-over-HTTPS server did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (['router.php', 'answer.json', 'requests.log', 'server.out'] as $file) {
            if (is_file($this->dir . '/' . $file)) {
                unlink($this->dir . '/' . $file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /** @return array<string,mixed> */
    private static function confirmedCase(): array
    {
        foreach ((array) VectorCase::read('live-dns.json')['cases'] as $case) {
            $case = VectorCase::map($case);
            $liveDns = VectorCase::map($case['expect'])['liveDns'] ?? null;
            if (is_array($liveDns) && ($liveDns['status'] ?? null) === 'confirmed') {
                return $case;
            }
        }
        throw new \UnexpectedValueException('live-dns.json has no confirmed case');
    }

    public function testTheResolverIsAskedForItsJsonForm(): void
    {
        $case = self::confirmedCase();
        $docs = VectorCase::read('receipts/v4-did-documents.json');
        $chain = RegistryAnswers::vectorChain(VectorCase::map($case['answers']));
        $resolver = "http://127.0.0.1:{$this->port}/dns-query";
        $result = Agreely::verifyReceipt(VectorCase::read('receipts/a3v4.bundle.json'), [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'rpcUrl' => 'https://rpc.test',
            'chainId' => 84532,
            'httpPost' => $chain->post(...),
            'dohUrl' => $resolver,
        ]);
        $this->assertSame('confirmed', $result->liveDns['status'] ?? null);
        $this->assertSame($resolver, $result->liveDns['resolver'] ?? null);
        $this->assertSame(
            ['application/dns-json /dns-query?name=_agreely.demo-co.example&type=TXT'],
            array_values(array_filter(explode("\n", (string) file_get_contents($this->dir . '/requests.log')))),
        );
    }
}

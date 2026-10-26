# Publishing `agreely/sdk` (Packagist)

First public release: `0.1.0`. This package is MAINNET-bound: the verifier
defaults to Base mainnet (chainId 8453).

## Mainnet registry address (RE-CHECK EVERY RELEASE)

1. **The known registry addresses must match the LIVE deployments.** The map is
   `REGISTRY_BY_CHAIN` in `src/Verify/ReceiptVerifier.php`. The live Base mainnet
   (chainId 8453) AgreelyRegistry is `0x6F2cF94045cF0cbDD94338882c34D183575023E9`,
   deploy block 53113531. Base Sepolia (84532) is `0xf25D3F8E73648C797Be2CCCD428061f357567b9A`,
   deploy block 50213158, and stays an explicit opt-in for testing.

2. **VERIFY IT BEFORE EVERY PUBLISH.** This is pinned BY HAND: the SDK reads no
   config, and the app resolves its own address from `config/anchor-network.json`
   plus the `BASE_REGISTRY_ADDRESS` secret, so a registry redeploy does NOT
   propagate here. A superseded contract still exists on chain and still answers
   `eth_call`, but it holds none of the new anchors, so the anchor checks read
   `unavailable` on valid receipts.

   Two independent sources of truth, both cheap to check:

   ```sh
   # 1. the deploy artefact
   jq -r '.transactions[0].contractAddress' \
     ../agreely-contracts/broadcast/DeployRegistry.s.sol/8453/run-latest.json

   # 2. what the live verifier publishes
   curl -s https://app.agreely.ca/verify | grep -o 'registryAddress"[^,}]*'
   ```

   Both must equal the Base mainnet address, case-insensitively. The test suite pins the
   value, so a silent edit fails a test, but only THIS step catches a redeploy
   that the tests do not know about yet.

## Publish steps

There is no build step. Packagist reads a git tag.

```sh
composer test                                  # unit suite must be green
vendor/bin/phpstan analyse --memory-limit=1G   # level max, no errors
vendor/bin/phpcs                               # PSR-12, no errors
```

1. Push this repo to its public GitHub URL
   (https://github.com/agreely-protocol/sdk-php).
2. Submit `https://github.com/agreely-protocol/sdk-php` at
   https://packagist.org/packages/submit (one time), or rely on the GitHub
   webhook for later updates. The published Composer package name stays
   `agreely/sdk`; only the source repo moved.
3. **Packagist reads the git tag** (`v0.5.0` for the current release) as the
   released version. The human creates and pushes that tag at publish time, once the
   matching CHANGELOG entry is on `main`.

Notes:
- `composer.json` name is `agreely/sdk`, license `MIT`. It carries no version: the
  tag is the version.
- Release the same version as the TypeScript twin `@agreely/sdk`: both cover the same
  /v1 surface under the same names.
- Do not create the tag here; the human tags at publish time.

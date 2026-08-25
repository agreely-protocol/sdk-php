# Publishing `agreely/sdk` (Packagist)

First public release: `0.1.0`. This package is MAINNET-bound: the verifier
defaults to Base mainnet (chainId 8453).

## Mainnet registry address (RE-CHECK EVERY RELEASE)

1. **The pinned mainnet registry address must match the LIVE deployment.** The ONE
   constant in `src/Verify/ReceiptVerifier.php` holds it:

   ```php
   private const MAINNET_REGISTRY_ADDRESS = '0x23577fafFa306375028D33a559D0F95Ced9424DB';
   ```

   Live Base mainnet (chainId 8453) AgreelyRegistry: `0x23577fafFa306375028D33a559D0F95Ced9424DB`, deploy block
   48889369 (the 2026-07-19 redeploy carrying the DID-tagged anchor events).
   EIP-55 checksummed. Base Sepolia (84532) stays available as an explicit opt-in
   for testing.

2. **VERIFY IT BEFORE EVERY PUBLISH.** This is pinned BY HAND: the SDK reads no
   config, and the app resolves its own address from `config/anchor-network.json`
   plus the `BASE_REGISTRY_ADDRESS` secret, so a registry redeploy does NOT
   propagate here. A stale address is SILENT and worse than a missing one: the
   superseded contract still exists on chain and still answers `eth_getLogs`, it
   just holds no anchors, so every `documentAnchor` check returns `"fail"`, which
   reads as TAMPERING on a perfectly valid receipt. v0.2.0 shipped exactly that,
   pinned to the predecessor `0x1E3121CFB5dfE1ac0b0265790D2bdA709725cF8B`.

   Two independent sources of truth, both cheap to check:

   ```sh
   # 1. the deploy artefact
   jq -r '.transactions[0].contractAddress' \
     ../agreely-contracts/broadcast/DeployRegistry.s.sol/8453/run-latest.json

   # 2. what the live verifier publishes
   curl -s https://app.agreely.ca/verify | grep -o 'registryAddress"[^,}]*'
   ```

   Both must equal the constant, case-insensitively. The test suite pins the
   value, so a silent edit fails a test, but only THIS step catches a redeploy
   that the tests do not know about yet.

## Publish steps

There is no build step. Packagist reads a git tag.

```sh
composer test          # unit suite must be green
```

1. Push this repo to its public GitHub URL
   (https://github.com/agreely-protocol/sdk-php).
2. Submit `https://github.com/agreely-protocol/sdk-php` at
   https://packagist.org/packages/submit (one time), or rely on the GitHub
   webhook for later updates. The published Composer package name stays
   `agreely/sdk`; only the source repo moved.
3. **Packagist reads a `v0.1.0` git tag** as the released version. The human
   creates and pushes that tag at publish time.

Notes:
- `composer.json` name is `agreely/sdk`, license `MIT`.
- Keep the version at `0.1.0` (via the `v0.1.0` tag). Do not create the tag
  here; the human tags at publish time.

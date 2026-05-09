# ruleshub/cli (Composer wrapper)

Composer package that installs the [RulesHub CLI](https://ruleshub.dev) into your PHP project.

This package is a **wrapper**: it ships no PHP business logic. On `composer require`, a tiny Composer plugin downloads the matching native Rust binary from the canonical [GitHub Releases](https://github.com/lozymon/ruleshub/releases), verifies its SHA256 against the published `SHA256SUMS`, and places it at `vendor/bin/ruleshub`.

## Install

```bash
# per-project (recommended)
composer require --dev ruleshub/cli

# system-wide
composer global require ruleshub/cli
```

After install, `vendor/bin/ruleshub` (or `~/.composer/vendor/bin/ruleshub` for global) is on your `PATH`.

### About the "allow-plugins" prompt

The first time you run `composer require ruleshub/cli`, Composer will ask:

```
ruleshub/cli contains a Composer plugin which is currently not in your allow-plugins config.
Do you trust "ruleshub/cli" to execute code and wish to enable it now?
```

Answer **`y`** — Composer adds this to your `composer.json`:

```json
"config": {
  "allow-plugins": {
    "ruleshub/cli": true
  }
}
```

The plugin only does one thing: download the binary on install. You can read its source at [`src/Plugin.php`](https://github.com/lozymon/ruleshub/blob/main/packages-php/cli/src/Plugin.php) and [`src/Installer.php`](https://github.com/lozymon/ruleshub/blob/main/packages-php/cli/src/Installer.php).

## Requirements

- PHP 8.2+
- `ext-zip` (for Windows builds; no-op on Unix)
- `ext-openssl` (HTTPS download)
- `tar` (for Linux/macOS extraction)

## Supported platforms

The wrapper auto-detects your platform and downloads the matching binary:

| OS / arch           | Target                                                                         |
| ------------------- | ------------------------------------------------------------------------------ |
| Linux x86_64        | `x86_64-unknown-linux-musl` (statically linked, runs on any glibc/musl distro) |
| Linux ARM64         | `aarch64-unknown-linux-musl`                                                   |
| macOS Intel         | `x86_64-apple-darwin`                                                          |
| macOS Apple Silicon | `aarch64-apple-darwin`                                                         |
| Windows x86_64      | `x86_64-pc-windows-msvc`                                                       |

## Versioning

This package version always matches the binary version it installs — `ruleshub/cli:0.1.0-alpha.1` installs `ruleshub@0.1.0-alpha.1`.

## Override the version

Useful for testing or pinning to an older binary:

```bash
RULESHUB_VERSION=0.1.0-alpha.2 composer install
```

## What this package is **not**

- Not a PHP reimplementation of the CLI. The CLI is one Rust binary; every distribution channel (npm, pip, Composer, install scripts, GitHub Releases) ultimately runs the same bytes.
- Not a polyfill or fallback. If the binary download fails, the package leaves a placeholder shim in place that prints a clear error.

## Architecture

See [the CLI architecture doc](https://ruleshub.dev/docs/contributing/cli-architecture) for the full one-binary-many-wrappers model.

## License

MIT — same as the canonical CLI.

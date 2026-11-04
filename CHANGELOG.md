# Changelog

## Versions present in this repository

Only one version of the source is included in this repository:

| Version | File | Notes |
|---------|------|-------|
| 2.1 | `meow.php5` | The sole source file included. Declares `'version' => '2.1'` in its config array. |

## Version 2.1

This is the only version available. The following is verified from the source code of `meow.php5`:

- Single-file, zero-dependency PHP reverse shell and web shell
- Session-oriented command loop with built-in commands (`!help`, `!info`, `!perm`, `!cd`, `!pwd`, `!ls`, `!cat`, `!catb`, `!download`, `!upload`, `!upgrade`, `!fwd`, `!env`, `!php`, `!cleanup`, `!selfdestruct`, `!exit`)
- Authentication via SHA-256 token hash (plaintext never sent over the wire)
- Optional TLS (peer verification disabled; self-signed accepted)
- Exponential-backoff reconnection with jitter
- File transfer: `!download` (URL→host), `!upload` (base64→host), `!cat` (text), `!catb` (binary-safe base64)
- Interactive PTY upgrade (`!upgrade`) with graceful fallback
- Transient TCP port forwarding (`!fwd`), loopback-only by default, `--all` binds to `0.0.0.0`
- Process-title aliasing (`--alias`) and beacon jitter (`--jitter`)
- Web-shell mode with renameable request parameters
- Include-safe: no side effects when `include`d; opt-in trigger
- Side-effect ledger and cleanup (`!cleanup [--self]`, `!selfdestruct`)
- Configurable via CLI flags and `MEOW_*` environment variables

## Version history

No earlier or later versions are present in this repository. No version-to-version differences can be documented because only v2.1 is included. Do not infer changes from other sources; this changelog reflects only what is actually in the repository.

## Source integrity

The PHP source file `meow.php5` is provided as-is and is not modified by this repository. See `BASELINE-MANIFEST.md` for the SHA-256 hash used to verify source integrity.

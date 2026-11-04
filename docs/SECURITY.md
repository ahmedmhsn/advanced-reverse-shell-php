# Security

This document analyzes the security properties and limitations of `meow.php5` (v2.1), based strictly on reading the source code. Behavior is inferred from source, not from execution.

## Intended threat model

This tool is designed for authorized security research and penetration testing in isolated lab environments. It is not a hardened, production-grade remote-access tool. It provides remote command execution, file read/write, PHP evaluation, port forwarding, and self-deletion.

## Authentication

### Mechanism

- A single shared token is configured (default: `X82Wd3t0qasJ87D9hUqPXCl5`).
- In reverse-shell mode, the client sends `MEOW-AUTH <sha256(token)>\n` immediately after connecting. The plaintext token is **never** sent over the wire; only its SHA-256 hash.
- Comparison uses `hash_equals` (constant-time) when available, falling back to `===`.
- In web mode, the token is compared against the request parameter named by `web_key` (default `sid`).

### Limitations

| Limitation | Description |
|------------|-------------|
| **No token = no auth** | If `token` is empty (`''`), `meow_token_ok` returns `true` for any given value. Anyone who can reach host:port can drive the shell. A stderr warning is emitted in reverse mode unless `quiet` is set. |
| **Single shared secret** | There is no per-session, per-user, or time-bound authentication. Possession of the token grants full access. |
| **Hash only obscures plaintext** | Sending SHA-256(token) instead of plaintext prevents passive observers from learning the token, but an active attacker who captures the hash can replay it (the listener appears to accept any correctly-hashed token). There is no nonce, challenge-response, or binding to a specific connection. |
| **No mutual authentication** | The shell authenticates to the listener, but the listener does not authenticate to the shell. The shell will connect to any configured host:port. |
| **No rate limiting** | No authentication attempt throttling or lockout. |

### Recommendation

Always set a strong, unique `MEOW_TOKEN` (or `token` in the config). Never rely on the default. Treat the token as a high-value secret.

## TLS

### Mechanism

- Optional TLS via `stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)`.
- SSL context sets `verify_peer => false`, `verify_peer_name => false`, `allow_self_signed => true`.
- The `peer_name` is set to the target host but is not used for verification.

### Limitations

| Limitation | Description |
|------------|-------------|
| **No peer verification** | `verify_peer` is disabled and self-signed certificates are accepted. TLS protects against passive eavesdropping but **not** against active man-in-the-middle attacks. |
| **No certificate pinning** | No pinning of expected listener certificates. |
| **TLS only on the callback** | TLS encrypts the shell connection only; it does not encrypt files on disk, command history, or listener-side logs. |

### Recommendation

Use TLS to prevent passive network observation, but understand it does not authenticate the listener. For stronger protection, combine with network-level controls (VPN, private IP ranges, firewall rules).

## Command execution risks

### Mechanism

Commands are executed via the first available of: `proc_open`, `shell_exec`, `passthru`, `system`, `exec`, `popen`. Output and exit code are captured. A wall-clock timeout (via the `timeout` binary) can cap execution time.

### Limitations / risks

| Risk | Description |
|------|-------------|
| **Arbitrary command execution** | Any command runs with the privileges of the PHP process. If the PHP process runs as root/www-data/other privileged account, compromise of the shell implies compromise of that account. |
| **`disable_functions` may neutralize the shell** | If all execution primitives are disabled via `disable_functions`, the shell reports `[!] no usable command primitive` and cannot run commands. |
| **`!php <code>`** | Evaluates arbitrary PHP in the shell process with full access to the process's memory, filesystem (subject to `open_basedir`), and extensions. |
| **No sandbox** | No container, jail, or restricted execution environment. |

### Recommendation

Run only in an isolated lab. Understand the privilege level of the PHP process before deploying. Treat any command output as potentially sensitive.

## File access risks

### `!cat` / `!catb`

- Reads any file the PHP process can read, subject to `open_basedir`.
- `!cat` caps at 1 MiB; `!catb` caps at 10 MiB.

### `!download`

- Writes remote content to a local path chosen by the operator.
- Subject to `open_basedir` and filesystem permissions.
- Recorded in the side-effect ledger for `!cleanup`.

### `!upload`

- Writes base64-decoded content to a local path (50 MiB base64 ceiling).
- Subject to `open_basedir` and filesystem permissions.
- Recorded in the side-effect ledger for `!cleanup`.

### Risks

| Risk | Description |
|------|-------------|
| **Arbitrary file read** | `!cat`/`!catb` can read any readable file (e.g., `/etc/passwd`, source code, credentials). |
| **Arbitrary file write** | `!download`/`!upload` can write any path the PHP process can write to, potentially overwriting critical files. |
| **No path traversal guards beyond `open_basedir`** | The code performs no additional path sanitization. |

### Recommendation

Run under a restricted, unprivileged account with tight `open_basedir` and filesystem permissions if testing in a lab.

## Network exposure

### Reverse shell callback

- The shell connects outbound to `host:port` (default `192.168.173.57:6665`).
- Outbound connectivity from the target to the listener is required.
- Reconnection uses exponential backoff with jitter.

### Port forwarding (`!fwd`)

- `!fwd [--all] <lport> <rhost> <rport>` forks a detached TCP relay.
- Loopback-only (`127.0.0.1`) by default; `--all` binds to `0.0.0.0`, exposing the forwarder to all interfaces.
- Requires `pcntl_fork`.
- Forwarders persist independently of the shell session.

### Web shell

- When accessed over HTTP, commands are executed in response to requests carrying the token parameter.
- `X-Powered-By` is removed; errors are suppressed; `Cache-Control: no-store` is set.

### Risks

| Risk | Description |
|------|-------------|
| **Callback interception** | Anyone on the network path between target and listener can observe or modify the traffic (mitigated partially by TLS, but no peer verification). |
| **Port forwarder exposure** | `--all` exposes a TCP forwarder on all interfaces, potentially bridging internal networks. |
| **Web shell discovery** | The web-shell endpoint is discoverable via the file URL; parameter names can be renamed but the endpoint remains. |
| **No encryption at rest** | No encryption of files, session data, or logs on disk. |

### Recommendation

Use firewall rules to restrict callback targets, avoid `--all` unless loopback forwarding is insufficient, and use TLS. Limit web-shell accessibility via network segmentation.

## Process and cleanup risks

### Process management

- `cli_set_process_title()` can rename the process (tradecraft; truncated to 15 chars on Linux).
- PTY upgrade spawns a local shell process.
- Port forwarders fork detached children that persist beyond the session.

### Cleanup (`!cleanup` / `!selfdestruct`)

- `!cleanup` unlinks only files recorded in the side-effect ledger (by `!download`/`!upload`) and kills only forwarders recorded in the ledger. Arbitrary files are not touched.
- `!cleanup --self` additionally deletes the shell file itself.
- `!selfdestruct` deletes the shell file and exits immediately.

### Risks

| Risk | Description |
|------|-------------|
| **Incomplete cleanup** | Files written outside `!download`/`!upload` (e.g., by arbitrary shell commands via `> redirection`) are **not** tracked and will not be removed by `!cleanup`. |
| **Orphaned forwarders** | If `!cleanup` is not run before session exit, forked forwarder children may persist. |
| **Self-deletion is irreversible** | `!selfdestruct` and `!cleanup --self` permanently remove the shell file. |
| **Race conditions** | `!cleanup` checks PID aliveness with `posix_kill($pid, 0)` then signals; a PID could be reused between check and signal. |

### Recommendation

Run `!cleanup` (or `!cleanup --self`) before ending a session to remove tracked artifacts. Understand that artifacts created outside the ledger (shell redirects, temp files from other tools) will remain.

## Blending-in features (tradecraft, not stealth)

The source implements several features described as "blending in":

- Process-title alias (`--alias`) — makes `ps` show a chosen name (e.g., `nginx`). Truncated to 15 characters on Linux.
- Beacon jitter (`--jitter`) — randomizes callback intervals to defeat naive fixed-interval detection.
- Web-mode warning suppression and parameter renaming — reduces visibility in access logs and error output.

### Important caveat

The source itself states:

> "features that make you hard to FIND are in; features that make you hard to PROVE -- falsified times, a destroyed audit trail, a blinded sensor -- are not."

These are **tradecraft, not stealth guarantees**. They do not defeat determined detection, EDR, network monitoring, or forensic analysis.

## Defensive recommendations

If you are defending against this class of tool:

1. **Restrict outbound connectivity** from web/application servers to only required destinations (egress firewalling).
2. **Disable PHP execution primitives** (`proc_open`, `shell_exec`, `passthru`, `system`, `exec`, `popen`) via `disable_functions` where not needed.
3. **Enforce `open_basedir`** to limit filesystem access.
4. **Monitor for** unknown outbound connections, unusual process names, `php` processes spawning shells, and base64 command parameters in web requests.
5. **Review access logs** for webshell-style parameter patterns and renamed parameters.
6. **Run PHP under an unprivileged, isolated account** with minimal filesystem access.
7. **Use EDR/auditing** to detect process-title changes, `proc_open` usage, and forked detached children.

## Summary

`meow.php5` is a capable single-file reverse shell with authentication (SHA-256 hash), optional TLS, PTY, file transfer, port forwarding, and cleanup. Its security properties are deliberately limited by design: single shared token, no peer verification in TLS, unrestricted command execution, and tradecraft-level blending-in. Use only in authorized, isolated lab environments and treat all configuration, tokens, and session data as sensitive.

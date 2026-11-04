# Commands

This document describes every implemented command in `meow.php5` (v2.1), based strictly on reading the source code. Behavior is inferred from source, not from execution.

## Command grammar

Any line that does not start with `!` is handed to the shell verbatim (via `sh -c`), so pipes, redirection, quoting, and job control behave normally.

`!` introduces a built-in. A command that begins with `!` but is not a recognized built-in is still reachable: `!!<cmd>` delivers it with the `!` escaped for the shell, so `!` never falls through to builtin dispatch.

- `!!<cmd>` — `<cmd>` is executed in the shell with a literal leading `!` (the shell sees `\!cmd`, i.e. the `!` is escaped).

## Built-in command reference

### !help

**Syntax:** `!help`

**Behavior:** Prints the full list of built-in commands with short descriptions to the session socket.

**Parameters:** None.

**Required permissions:** None (available in any active session).

**Limitations:** None beyond session availability.

---

### !info

**Syntax:** `!info`

**Behavior:** Prints a host + PHP capability report:

- Host section: `id -a`, `uname -a`, `hostname`, OS release, IP address, directory listing of cwd (on POSIX); `whoami`, `hostname`, `ver`, `systeminfo` (on Windows)
- PHP section: version, SAPI, OS, user, cwd, self path (`__FILE__`), available execution primitives, `disable_functions`, `open_basedir`, `allow_url_fopen`, pcntl, posix, curl availability

**Parameters:** None.

**Required permissions:** None.

**Limitations:** Host commands are subject to the same execution-primitive and `disable_functions` constraints as any other command.

---

### !perm

**Syntax:** `!perm`

**Behavior:** Prints a permissions report:

- `exec primitives` line listing available primitives
- Per-primitive OK/BLOCKED status for `proc_open`, `shell_exec`, `passthru`, `system`, `exec`, `popen`
- `disable_functions` value
- `open_basedir` value
- `safe_mode` note ("removed in 5.4, always off")

**Parameters:** None.

**Required permissions:** None.

**Limitations:** Reflects the PHP process's actual configuration at runtime.

---

### !cd

**Syntax:** `!cd <dir>`

**Behavior:** Changes the tracked working directory.

- With no argument: resets `$meowCwd` to null (forces re-detection from `getcwd()` on next use) and prints `[~] cwd reset`.
- With a relative path: resolved against the current cwd.
- Resolves via `realpath`; only changes cwd if the target is an existing directory. Otherwise prints `[-] not a directory: <target>`.

**Parameters:** `<dir>` — target directory path.

**Required permissions:** The PHP process must have filesystem access to the target directory.

**Limitations:** Only directories that exist and are readable by the PHP process can be entered. The change affects the `meowCwd` tracking used by command execution (`meow_cwd()`).

---

### !pwd

**Syntax:** `!pwd`

**Behavior:** Prints the current working directory.

**Parameters:** None.

**Required permissions:** None.

**Limitations:** Reports the tracked cwd, which falls back to `getcwd()` or `C:\`/`/` if unavailable.

---

### !ls

**Syntax:** `!ls [dir]`

**Behavior:** Runs `ls -la <dir>` (or cwd if no dir given) via `meow_exec` with a 15-second timeout and prints the result.

**Parameters:** `[dir]` — optional directory to list.

**Required permissions:** The `ls` binary must exist and be executable; the PHP process must be able to list the directory.

**Limitations:** Relies on the external `ls` binary; a 15-second execution timeout applies.

---

### !cat

**Syntax:** `!cat <file>`

**Behavior:** Prints the contents of a text file.

- If `<file>` is not a file: prints `[-] not a file: <file>`.
- If size > 1 MiB (1,048,576 bytes): prints `[!] >1 MiB, use !catb` and refuses to print.
- Otherwise: prints file contents normalized to UTF-8 via `meow_utf8`.

**Parameters:** `<file>` — path to the file.

**Required permissions:** The PHP process must be able to read the file.

**Limitations:** 1 MiB size cap; text-oriented (not binary-safe — use `!catb` for binary files).

---

### !catb

**Syntax:** `!catb <file>`

**Behavior:** Binary-safe file read.

- If `<file>` is not a file: prints `[-] not a file: <file>`.
- If size > 10 MiB: prints `[!] >10 MiB, use !download` and refuses.
- Otherwise: prints `<path> <byte_count>\n<base64_encoded_contents>\n`.

**Parameters:** `<file>` — path to the file.

**Required permissions:** The PHP process must be able to read the file.

**Limitations:** 10 MiB size cap. Output is base64-encoded to safely carry NUL bytes.

---

### !download

**Syntax:** `!download <url> [path]`

**Behavior:** Pulls a remote file onto this host.

- `<url>` must pass `FILTER_VALIDATE_URL` or start with `http`.
- `[path]` defaults to the basename of the URL's path component, or `download` if empty.
- Uses `meow_fetch($url)` (URL fopen → cURL extension → curl/wget binary) to retrieve content.
- Writes via `file_put_contents($path, $data)`.
- On success: records `['write', $path, strlen($data)]` in the ledger and prints `[+] <url> -> <path> (<bytes> bytes)`.
- On fetch failure: prints `[-] fetch failed: <url>`.
- On write failure: prints `[-] write failed: <path>`.

**Parameters:** `<url>` — remote URL; `[path]` — optional local destination.

**Required permissions:** `allow_url_fopen` or cURL or curl/wget binary must be available; filesystem write permission to the destination.

**Limitations:** SSL certificate verification is disabled (self-signed accepted). 30-second fetch timeout, up to 5 redirects. Subject to `open_basedir` restrictions.

---

### !upload

**Syntax:** `!upload <path>`

**Behavior:** Receives a base64-encoded file from the session.

- Prints `[?] base64 now, end with a line containing only '.'`.
- Reads lines from the socket until a line containing only `.` (or empty line / EOF).
- Base64 input ceiling: 50 MiB.
- `base64_decode(..., false)` (strict); on malformed input prints `[-] malformed base64`.
- Writes via `file_put_contents($path, $data)`.
- On success: records `['write', $path, strlen($data)]` and prints `[+] wrote <path> (<bytes> bytes)`.
- On write failure: prints `[-] write failed: <path>`.

**Parameters:** `<path>` — local destination path.

**Required permissions:** Filesystem write permission to the destination.

**Limitations:** 50 MiB base64 ceiling; strict base64 decoding; subject to `open_basedir` restrictions. The upload is tracked in the ledger for `!cleanup`.

---

### !upgrade

**Syntax:** `!upgrade`

**Behavior:** Swaps the current line-at-a-time session to an interactive PTY session.

- On Windows: prints `[-] PTY upgrade unavailable on Windows`.
- Otherwise: spawns a local shell via `proc_open` (`script -qfc`, python pty, or `<shell> -i`), then multiplexes byte-for-byte between the socket and the child process.
- Built-ins are still intercepted inside the PTY (`!help`, `!info`, etc.); `!exit` leaves the PTY and returns to the line-at-a-time session; `!upgrade` inside a PTY prints `[!] already inside a PTY`.
- Terminal control sequences pass straight through (full-screen programs, sudo prompts, arrow keys, tab-completion).

**Parameters:** None.

**Required permissions:** `proc_open` must be available; `script`, `python3`, or `python` preferred for a real PTY.

**Limitations:** Unavailable on Windows. Falls back to `<shell> -i` if no PTY helper exists.

---

### !fwd

**Syntax:** `!fwd [--all] <lport> <rhost> <rport>`

**Behavior:** Starts a background TCP forwarder.

- `--all` (or `-a`) binds to `0.0.0.0`; otherwise binds to `127.0.0.1` (loopback-only).
- Validates ports 1–65535.
- Forks via `pcntl_fork`; parent records `['fwd', $pid, $bind, $lport, $rhost, $rport]` and prints `[+] <bind>:<lport> -> <rhost>:<rport> (pid <pid>)`.
- Child detaches (`posix_setsid`, closes session socket/stdio), binds a TCP server, accepts clients, and forks a relay child per connection that pumps bytes between the local client and `tcp://<rhost>:<rport>`.

**Parameters:** `[--all]` — bind to all interfaces; `<lport>` — local listen port; `<rhost>` — remote target host; `<rport>` — remote target port.

**Required permissions:** `pcntl_fork` must exist; local port binding permission; outbound TCP to `<rhost>:<rport>`.

**Limitations:** Requires `pcntl` extension. Forwarders persist independently of the session and are tracked for `!cleanup`. Loopback-only by default (use `--all` to expose externally).

---

### !env

**Syntax:** `!env`

**Behavior:** Dumps environment variables:

- All scalar `$_SERVER` entries (prefixed `S `)
- All scalar `$_ENV` entries (prefixed `E `)
- Output of the `env` command (10-second timeout)

**Parameters:** None.

**Required permissions:** None.

**Limitations:** Environment exposure depends on SAPI configuration; the `env` binary may not exist.

---

### !php

**Syntax:** `!php <code>`

**Behavior:** Evaluates arbitrary PHP code in the shell process via `eval($code . ';')`. Output buffering captures echo/print output; exceptions are caught and their messages printed.

**Parameters:** `<code>` — PHP code to evaluate.

**Required permissions:** The PHP process must allow `eval` (not disabled by `disable_functions`).

**Limitations:** Arbitrary code execution with the privileges of the PHP process. Output is captured via output buffering; errors/exceptions are reported.

---

### !cleanup

**Syntax:** `!cleanup [--self]`

**Behavior:** Reverts side effects tracked by the side-effect ledger.

- For each `['write', $path, $bytes]` (recorded by `!download`/`!upload`): unlinks the file if it exists, reporting success or failure.
- For each `['fwd', $pid, $bind, $lport, $rhost, $rport]` (recorded by `!fwd`): if the PID is alive, sends `SIGTERM`, waits 150ms, then `SIGKILL` if still alive.
- Resets `$meowCwd` (forces re-detection from `getcwd()`).
- Reports `[*] reverted <n> file(s), stopped <n> forward(s)`.
- With `--self`: prints a notice, closes the socket, `unlink(__FILE__)`, and `exit(0)`.

**Parameters:** `[--self]` — also delete the shell file itself and exit.

**Required permissions:** Filesystem write/unlink permission for tracked files; `posix_kill` for forwarders.

**Limitations:** Only paths recorded by `!download`/`!upload` are cleaned up — arbitrary files are not touched. Forwarder termination relies on `posix_kill`.

---

### !selfdestruct

**Syntax:** `!selfdestruct`

**Behavior:** Deletes the shell file itself and exits.

- Prints `[*] removing <__FILE__>`.
- Waits 60ms to let the line flush.
- Closes the socket.
- `unlink(__FILE__)`.
- `exit(0)`.

**Parameters:** None.

**Required permissions:** Filesystem unlink permission on the shell file.

**Limitations:** Irreversible — the shell deletes itself.

---

### !exit

**Syntax:** `!exit`

**Behavior:** Closes the session and returns control (in reverse mode, the reconnect loop may establish a new connection).

**Parameters:** None.

**Required permissions:** None.

**Limitations:** None.

---

## Non-built-in shell commands

Any text not starting with `!` is executed as a shell command via `meow_exec`. This means:

- Standard shell builtins (`cd`, `export`, `exit`, etc.) work when a POSIX shell is available.
- Pipes (`|`), redirection (`>`, `<`, `>>`), quoting (`'`, `"`, `` ` ``), and backgrounding (`&`) work.
- Execution primitives are tried in order: `proc_open` → `shell_exec` → `passthru` → `system` → `exec` → `popen`.
- If none are available, returns `[!] no usable command primitive (disable_functions?)` with exit code 1.
- A wall-clock timeout (`exec_timeout`, default 30s) can be applied via the `timeout` binary when available.

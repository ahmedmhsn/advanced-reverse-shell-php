# Diagram 1 — System Architecture

## Mermaid Diagram

```mermaid
graph TB
    %% External entities
    CLI_Operator["CLI Operator / Researcher"]:::external
    HTTP_Client["HTTP Client / Browser"]:::external
    Remote_Listener["Remote TCP/TLS Listener"]:::external

    %% Entry and config
    Entry["Entry Block<br/>lines 1443-1461"]:::entry
    Config["Configuration ($GLOBALS['meow'])<br/>lines 1417-1441<br/>CLI + MEOW_* env vars"]:::config

    %% Mode dispatch
    WebMode["Web Shell Mode<br/>meow_web()<br/>lines 1365-1411"]:::mode
    ReverseMode["Reverse Shell Mode<br/>meow_reverse()<br/>lines 1323-1359"]:::mode

    %% Transport
    Transport["Transport Layer<br/>meow_connect()<br/>lines 1276-1312<br/>TCP + optional TLS"]:::transport
    Handshake["Authentication Handshake<br/>meow_handshake()<br/>lines 1314-1321<br/>SHA-256 token hash"]:::auth

    %% Session
    Session["Session Loop<br/>meow_session()<br/>lines 1170-1214"]:::session
    BuiltIns["Built-in Commands<br/>meow_builtin()<br/>lines 868-1163"]:::builtin
    PTYSession["PTY Session<br/>meow_pty_session()<br/>lines 641-750"]:::pty

    %% Execution
    Exec["Command Execution<br/>meow_exec() / meow_raw_exec()<br/>lines 398-416 / 308-364"]:::exec

    %% Utilities
    Caps["Capabilities Detection<br/>meow_caps()<br/>lines 277-300"]:::util
    Fetch["Remote Fetch<br/>meow_fetch()<br/>lines 526-584"]:::util
    PTYCmd["PTY Command Builder<br/>meow_pty_command()<br/>lines 608-624"]:::util
    Forward["Port Forwarder<br/>meow_forward()<br/>lines 776-825"]:::util
    Ledger["Side-Effect Ledger<br/>meow_note() / meow_effects()<br/>lines 1262-1270"]:::util

    %% Relationships
    CLI_Operator --> Entry
    HTTP_Client --> Entry
    Remote_Listener -.->|callback target| ReverseMode

    Entry --> Config
    Entry -->|meow_entry()| WebMode
    Entry -->|meow_entry()| ReverseMode

    WebMode --> Handshake
    ReverseMode --> Transport
    Transport --> Handshake
    Handshake --> Session

    Session --> BuiltIns
    Session --> PTYSession
    Session --> Exec

    BuiltIns --> Fetch
    BuiltIns --> PTYCmd
    BuiltIns --> Forward
    BuiltIns --> Ledger
    BuiltIns --> Exec

    Exec --> Caps

    classDef external fill:#e1f5e1,stroke:#2e7d32,stroke-width:2px
    classDef entry fill:#fff3e0,stroke:#ef6c00,stroke-width:2px
    classDef config fill:#e3f2fd,stroke:#1565c0,stroke-width:2px
    classDef mode fill:#f3e5f5,stroke:#7b1fa2,stroke-width:2px
    classDef transport fill:#e8eaf6,stroke:#3f51b5,stroke-width:2px
    classDef auth fill:#ffebee,stroke:#c62828,stroke-width:2px
    classDef session fill:#e0f2f1,stroke:#00695c,stroke-width:2px
    classDef builtin fill:#fce4ec,stroke:#880e4f,stroke-width:2px
    classDef pty fill:#fff8e1,stroke:#f57f17,stroke-width:2px
    classDef exec fill:#f1f8e9,stroke:#33691e,stroke-width:2px
    classDef util fill:#fafafa,stroke:#424242,stroke-width:1px
```

## Explanation of Major Nodes

| Node | Responsibility | Source Reference |
|------|---------------|------------------|
| **Entry Block** | Initializes configuration, checks include trigger, dispatches to web or reverse mode | lines 1417-1461 |
| **Configuration** | Holds all runtime settings (host, port, token, TLS, retry, etc.) populated from defaults, CLI args, and `MEOW_*` environment variables | lines 126-171, 1417-1441 |
| **Web Shell Mode** | HTTP request handler; validates token, reads base64/raw command from request params, executes and returns output | lines 1365-1411 |
| **Reverse Shell Mode** | Main reverse-shell loop; connects to remote listener, performs handshake, runs session, reconnects with backoff | lines 1323-1359 |
| **Transport Layer** | Opens TCP stream to host:port, optionally upgrades to TLS (peer verification disabled) | lines 1276-1312 |
| **Authentication Handshake** | Sends `MEOW-AUTH <sha256(token)>` over the socket; plaintext token never transmitted | lines 1314-1321 |
| **Session Loop** | Line-at-a-time interactive session; reads input, dispatches built-ins or executes shell commands, reprints prompt | lines 1170-1214 |
| **Built-in Commands** | Dispatches `!` prefixed commands (help, info, perm, cd, pwd, ls, cat, catb, download, upload, upgrade, fwd, env, php, cleanup, selfdestruct, exit) | lines 868-1163 |
| **PTY Session** | Interactive pseudo-terminal session with byte-for-byte multiplexing between socket and child shell process | lines 641-750 |
| **Command Execution** | Tries execution primitives in order: proc_open → shell_exec → passthru → system → exec → popen; supports wall-clock timeout via `timeout` binary | lines 308-416 |
| **Capabilities Detection** | Detects which execution primitives are available and not disabled via `disable_functions` | lines 277-300 |
| **Remote Fetch** | Retrieves remote files via URL fopen, cURL extension, or curl/wget binaries (SSL verification disabled) | lines 526-584 |
| **Port Forwarder** | Forks detached TCP relay via `pcntl_fork`; loops back bound by default, `--all` binds to 0.0.0.0 | lines 776-825 |
| **Side-Effect Ledger** | Tracks file writes and port forwards for `!cleanup` reversal | lines 1262-1270 |

## Important Relationships

1. **Entry → Config**: Configuration is initialized before any mode dispatch.
2. **Entry → Mode**: `meow_entry()` determines if the file is the entry point; if HTTP and web enabled → `meow_web()`, otherwise → `meow_reverse()`.
3. **Reverse Mode → Transport → Handshake → Session**: The reverse shell connects, authenticates, then runs the interactive session.
4. **Web Mode → Handshake**: Web mode validates token via `meow_token_ok()` against request parameter.
5. **Session → BuiltIns → Exec**: Built-in commands are intercepted; non-built-in input falls through to `meow_exec()`.
6. **BuiltIns → Ledger**: File writes (download/upload) and port forwards are recorded for cleanup.

## Source-Code References

- Config defaults: lines 1417-1441
- Entry dispatch: lines 1454-1461
- Web mode: lines 1365-1411
- Reverse mode: lines 1323-1359
- Transport: lines 1276-1312
- Handshake: lines 1314-1321
- Session: lines 1170-1214
- Built-ins: lines 868-1163
- PTY session: lines 641-750
- Command execution: lines 308-416
- Capabilities: lines 277-300
- Fetch: lines 526-584
- Port forwarding: lines 776-825
- Ledger: lines 1262-1270

## Security Observations

- **Token is single shared secret**: Anyone who knows the token can drive the shell; no per-session authentication.
- **SHA-256 hash sent, not plaintext**: Prevents passive eavesdropping of the token but does not prevent replay.
- **TLS peer verification disabled**: `verify_peer => false`, `verify_peer_name => false`, `allow_self_signed => true` — protects against passive observation but not active MITM.
- **Web mode suppresses errors**: `display_errors=0`, `log_errors=0`, removes `X-Powered-By` header.
- **No source-code obfuscation**: The file is readable plaintext PHP.

## Known Limitations and Uncertainties

- No formal PHP version compatibility matrix verified; code avoids PHP 8-only syntax.
- TLS does not authenticate the listener to the shell (no mutual authentication).
- The entry detection relies on `SCRIPT_FILENAME` which may behave differently across SAPIs.
- Behavior under `php -r`, `php -R`, or piped stdin is treated as an eval (no file invoked).

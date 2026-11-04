# Diagram 2 — High-Level Execution Flow

## Mermaid Diagram

```mermaid
flowchart TD
    Start[("Start: php meow.php5")]:::start
    ConfigInit["meow_cfg()<br/>Initialize $GLOBALS['meow']<br/>from defaults + CLI + MEOW_* env<br/>lines 126-171, 1417-1441"]:::process
    EntryCheck["meow_entry()<br/>Is this file the entry point?<br/>lines 225-242"]:::decision
    RunOnInclude["runOnInclude check<br/>$_GET[run_key] === run_val<br/>or MEOW_RUN_ON_INCLUDE env<br/>lines 1445-1452"]:::decision
    
    NoEntry["No side effects when included<br/>Functions available to includer only<br/>lines 1443-1444"]:::terminal

    IsHTTP["meow_http()<br/>Is SAPI non-CLI?<br/>lines 212-216"]:::decision

    WebMode["meow_web()<br/>HTTP request handler<br/>lines 1365-1411"]:::process
    ReverseMode["meow_reverse()<br/>Reverse shell main loop<br/>lines 1323-1359"]:::process

    Disguise["meow_disguise()<br/>Set process title alias<br/>lines 1231-1238"]:::process
    NoTokenWarning["Warn if no token set<br/>(unless quiet mode)<br/>lines 1330-1335"]:::process

    Connect["meow_connect()<br/>Open TCP/TLS to host:port<br/>lines 1276-1312"]:::process
    ConnectOK{"Connection<br/>successful?"}:::decision
    Handshake["meow_handshake()<br/>Send SHA-256(token)<br/>lines 1314-1321"]:::process
    Session["meow_session()<br/>Interactive command loop<br/>lines 1170-1214"]:::process
    Close["Close socket<br/>lines 1342-1343"]:::process

    RetryCheck{"retry enabled?"}:::decision
    MaxTries{"retry_max reached?"}:::decision
    Backoff["Exponential backoff + jitter<br/>delay = min(60, delay * 2^tries)<br/>then meow_jitter()<br/>lines 1354-1357"]:::process

    End[("End")]:::end

    Start --> ConfigInit
    ConfigInit --> EntryCheck
    EntryCheck -->|No| RunOnInclude
    RunOnInclude -->|No| NoEntry
    RunOnInclude -->|Yes| IsHTTP
    EntryCheck -->|Yes| IsHTTP

    IsHTTP -->|Yes| WebMode
    IsHTTP -->|No| ReverseMode

    WebMode --> End

    ReverseMode --> Disguise
    Disguise --> NoTokenWarning
    NoTokenWarning --> Connect
    Connect --> ConnectOK
    ConnectOK -->|No| RetryCheck
    ConnectOK -->|Yes| Handshake
    Handshake --> Session
    Session --> Close
    Close --> RetryCheck

    RetryCheck -->|No| End
    RetryCheck -->|Yes| MaxTries
    MaxTries -->|Yes| End
    MaxTries -->|No| Backoff
    Backoff --> Connect

    classDef start fill:#e1f5e1,stroke:#2e7d32,stroke-width:2px
    classDef process fill:#e3f2fd,stroke:#1565c0,stroke-width:2px
    classDef decision fill:#fff3e0,stroke:#ef6c00,stroke-width:2px
    classDef terminal fill:#f5f5f5,stroke:#9e9e9e,stroke-width:2px
    classDef end fill:#ffebee,stroke:#c62828,stroke-width:2px
```

## Explanation of Major Nodes

| Node | Responsibility | Source Reference |
|------|---------------|------------------|
| **Config Init** | `meow_cfg()` builds `$GLOBALS['meow']` from defaults, CLI positional args, flags, and `MEOW_*` environment variables | lines 126-171, 1417-1441 |
| **Entry Check** | `meow_entry()` returns true only when the file is invoked directly (not included) | lines 225-242 |
| **RunOnInclude** | Opt-in trigger: `$_GET[run_key] === run_val` or `MEOW_RUN_ON_INCLUDE` env var | lines 1445-1452 |
| **No Entry** | When included without trigger: no side effects, functions available to includer | lines 1443-1444 |
| **Is HTTP** | `meow_http()` checks `PHP_SAPI` — true for non-CLI SAPIs (fpm, mod_php, dev server) | lines 212-216 |
| **Web Mode** | `meow_web()` handles HTTP requests: token check, command read, execute, echo output | lines 1365-1411 |
| **Reverse Mode** | `meow_reverse()` is the blocking reverse-shell loop with reconnection | lines 1323-1359 |
| **Disguise** | `meow_disguise()` calls `cli_set_process_title()` if alias configured | lines 1231-1238 |
| **No Token Warning** | Stderr warning emitted when token is empty and quiet is false | lines 1330-1335 |
| **Connect** | `meow_connect()` opens TCP stream, optionally upgrades to TLS | lines 1276-1312 |
| **Handshake** | `meow_handshake()` sends `MEOW-AUTH <sha256(token)>` | lines 1314-1321 |
| **Session** | `meow_session()` runs the interactive line-at-a-time command loop | lines 1170-1214 |
| **Close** | Socket is closed after session ends | lines 1342-1343 |
| **Retry/Backoff** | Exponential backoff (5, 10, 20, 40s capped at 60s) with jitter; `retry_max=0` means forever | lines 1347-1358 |

## Important Relationships

1. **Config → Entry → Mode**: Configuration loads first, then entry determination, then mode dispatch (web vs reverse).
2. **Web Mode is terminal**: HTTP mode handles one request and returns; no reconnection loop.
3. **Reverse Mode loops**: On disconnect, reconnects with exponential backoff unless retry disabled or `retry_max` reached.
4. **Connect failure → Retry**: If `stream_socket_client` returns false, the flow goes to retry logic.
5. **Handshake → Session**: Authentication handshake must complete before the session starts.

## Source-Code References

- Config: lines 126-171, 1417-1441
- Entry detection: lines 225-242
- HTTP detection: lines 212-216
- Web mode: lines 1365-1411
- Reverse mode: lines 1323-1359
- Disguise: lines 1231-1238
- Connect: lines 1276-1312
- Handshake: lines 1314-1321
- Session: lines 1170-1214
- Retry/backoff: lines 1337-1358

## Security Observations

- **No token = open access**: If `token` is empty, `meow_token_ok()` returns true for any value; anyone reaching host:port can drive the shell.
- **Warning may be suppressed**: `quiet` mode suppresses the no-token stderr warning.
- **Reconnect forever**: Default `retry_max = 0` means the shell will attempt reconnection indefinitely.
- **TLS without peer verification**: Connection is encrypted but the listener's identity is not verified.

## Known Limitations and Uncertainties

- `SCRIPT_FILENAME` may not be set in all SAPIs; empty value is treated as HTTP direct request.
- Web mode handles a single request per HTTP invocation; no persistent HTTP session.
- Backoff delay calculation: `min(60, retry_delay * (1 << min(tries, 4)))` then jittered — jitter factor is `1 - (2*random - 1) * jitter`.

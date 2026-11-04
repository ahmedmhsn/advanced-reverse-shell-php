# Diagram 4 — CLI vs HTTP Architecture

## Mermaid Diagram

```mermaid
graph LR
    %% Shared components
    Config["Shared Configuration<br/>$GLOBALS['meow']<br/>lines 1417-1441"]:::shared
    Caps["Capabilities Detection<br/>meow_caps()<br/>lines 277-300"]:::shared
    Exec["Command Execution<br/>meow_exec() / meow_raw_exec()<br/>lines 308-416"]:::shared
    Utils["Low-Level Utilities<br/>meow_out, meow_utf8,<br/>meow_windows, meow_cwd<br/>lines 178-271"]:::shared

    %% CLI path
    CLI_Entry["CLI Entry Point<br/>php meow.php5 [host] [port] [token] [flags]<br/>lines 1417-1461"]:::cli
    CLI_Config["CLI arg parsing<br/>positional + flags + MEOW_* env<br/>lines 126-171"]:::cli
    CLI_Reverse["meow_reverse()<br/>Blocking reverse-shell loop<br/>lines 1323-1359"]:::cli
    CLI_Connect["meow_connect()<br/>TCP + optional TLS<br/>lines 1276-1312"]:::cli
    CLI_Handshake["meow_handshake()<br/>SHA-256 token hash<br/>lines 1314-1321"]:::cli
    CLI_Session["meow_session()<br/>Line-at-a-time loop<br/>lines 1170-1214"]:::cli
    CLI_PTY["meow_pty_session()<br/>Interactive PTY<br/>lines 641-750"]:::cli
    CLI_Forward["meow_forward()<br/>TCP port forwarding<br/>lines 776-825"]:::cli
    CLI_Disguise["meow_disguise()<br/>Process title alias<br/>lines 1231-1238"]:::cli

    %% HTTP path
    HTTP_Entry["HTTP Entry Point<br/>Web request to meow.php5<br/>lines 1454-1458"]:::http
    HTTP_Web["meow_web()<br/>HTTP request handler<br/>lines 1365-1411"]:::http
    HTTP_TokenCheck["Token check via $_GET[web_key]<br/>default: 'sid'<br/>lines 1384-1385"]:::http
    HTTP_CmdRead["Read command from request params<br/>web_b64 (base64) or web_cmd (raw)<br/>lines 1393-1402"]:::http
    HTTP_Exec["meow_exec($cmd, 60)<br/>60-second timeout<br/>line 1409"]:::http
    HTTP_Response["Echo output / 'ok' / 403<br/>lines 1404-1410"]:::http
    HTTP_Headers["Suppress errors, strip headers<br/>Content-Type: text/plain<br/>lines 1372-1381"]:::http

    %% Shared relationships
    CLI_Entry --> Config
    HTTP_Entry --> Config

    CLI_Reverse --> CLI_Disguise
    CLI_Reverse --> CLI_Connect
    CLI_Connect --> CLI_Handshake
    CLI_Handshake --> CLI_Session
    CLI_Session --> CLI_PTY
    CLI_Session --> CLI_Forward
    CLI_Session --> Exec
    CLI_Session --> Utils

    HTTP_Entry --> HTTP_Web
    HTTP_Web --> HTTP_Headers
    HTTP_Web --> HTTP_TokenCheck
    HTTP_TokenCheck -->|Pass| HTTP_CmdRead
    HTTP_TokenCheck -->|Fail| HTTP_Response
    HTTP_CmdRead --> HTTP_Exec
    HTTP_Exec --> Exec
    HTTP_Exec --> Utils
    HTTP_Exec --> HTTP_Response

    CLI_Session --> BuiltIns["Built-in Commands<br/>meow_builtin()<br/>lines 868-1163"]:::builtin
    BuiltIns --> Exec
    BuiltIns --> Fetch["meow_fetch()<br/>lines 526-584"]:::shared
    BuiltIns --> Ledger["Side-effect ledger<br/>lines 1262-1270"]:::shared

    classDef shared fill:#e3f2fd,stroke:#1565c0,stroke-width:2px
    classDef cli fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px
    classDef http fill:#fff3e0,stroke:#ef6c00,stroke-width:2px
    classDef builtin fill:#fce4ec,stroke:#880e4f,stroke-width:2px
```

## Explanation of Major Nodes

### Shared Components

| Component | Responsibility | Source Reference |
|-----------|---------------|------------------|
| **Configuration** | `$GLOBALS['meow']` config array with all runtime settings | lines 1417-1441 |
| **Capabilities Detection** | Detects available command execution primitives | lines 277-300 |
| **Command Execution** | `meow_exec()` / `meow_raw_exec()` — tries primitives in order with optional timeout | lines 308-416 |
| **Low-Level Utilities** | `meow_out`, `meow_utf8`, `meow_windows`, `meow_cwd` | lines 178-271 |
| **Built-in Commands** | `meow_builtin()` — dispatches `!` commands | lines 868-1163 |
| **Remote Fetch** | `meow_fetch()` — retrieves remote files | lines 526-584 |
| **Side-Effect Ledger** | `meow_note()` / `meow_effects()` — tracks writes and forwards | lines 1262-1270 |

### CLI Mode (Reverse Shell)

| Component | Responsibility | Source Reference |
|-----------|---------------|------------------|
| **CLI Entry** | Invoked via `php meow.php5 [host] [port] [token] [flags]` | lines 1417-1461 |
| **CLI Config Parsing** | Positional args + flags + `MEOW_*` env overrides | lines 126-171 |
| **Reverse Mode** | `meow_reverse()` — blocking loop with reconnection | lines 1323-1359 |
| **Connect** | `meow_connect()` — TCP/TLS to remote listener | lines 1276-1312 |
| **Handshake** | `meow_handshake()` — SHA-256 token hash | lines 1314-1321 |
| **Session** | `meow_session()` — interactive line-at-a-time loop | lines 1170-1214 |
| **PTY** | `meow_pty_session()` — interactive pseudo-terminal | lines 641-750 |
| **Port Forwarding** | `meow_forward()` — detached TCP relay | lines 776-825 |
| **Disguise** | `meow_disguise()` — process title alias | lines 1231-1238 |

### HTTP Mode (Web Shell)

| Component | Responsibility | Source Reference |
|-----------|---------------|------------------|
| **HTTP Entry** | Web request to `meow.php5` | lines 1454-1458 |
| **Web Mode** | `meow_web()` — HTTP request handler | lines 1365-1411 |
| **Headers** | Suppresses errors, removes `X-Powered-By`, sets content type | lines 1372-1381 |
| **Token Check** | Validates `$_GET[web_key]` against configured token | lines 1384-1385 |
| **Command Read** | Reads `web_b64` (base64) or `web_cmd` (raw) from POST/GET | lines 1393-1402 |
| **Execute** | `meow_exec($cmd, 60)` with 60-second timeout | line 1409 |
| **Response** | Echoes output, `ok`, or `403` | lines 1404-1410 |

## Important Relationships

1. **Shared config**: Both modes read from the same `$GLOBALS['meow']` configuration array.
2. **Shared execution**: Both modes ultimately route command execution through `meow_exec()` / `meow_raw_exec()`.
3. **CLI is persistent**: The reverse-shell loop continues until manually stopped or retry limits are reached.
4. **HTTP is stateless**: Each request is handled independently; no persistent connection or session state.
5. **CLI has PTY and forwarding**: These features are only available in CLI/reverse mode, not HTTP mode.
6. **Web mode has its own timeout**: Hardcoded 60-second timeout for command execution (line 1409).

## Source-Code References

- Config defaults: lines 1417-1441
- CLI arg parsing: lines 126-171
- Entry dispatch: lines 1454-1461
- Web mode: lines 1365-1411
- Reverse mode: lines 1323-1359
- Connect: lines 1276-1312
- Handshake: lines 1314-1321
- Session: lines 1170-1214
- PTY session: lines 641-750
- Port forwarding: lines 776-825
- Disguise: lines 1231-1238
- Built-ins: lines 868-1163
- Fetch: lines 526-584
- Ledger: lines 1262-1270
- Command execution: lines 308-416

## Security Observations

- **Web mode suppresses errors**: `display_errors=0`, `log_errors=0` — prevents information leakage via error messages.
- **Web mode strips headers**: Removes `X-Powered-By` to avoid identifying the PHP interpreter.
- **Web mode token via GET only**: Token is checked only in `$_GET[web_key]`, not in POST body.
- **CLI has no HTTP exposure**: No web server needed; connects outbound to a listener.
- **Web mode 60-second timeout**: Hardcoded timeout may allow long-running commands to complete.
- **CLI reconnect loop**: Default retry forever (`retry_max = 0`) increases exposure window.

## Known Limitations and Uncertainties

- Web mode does not support HTTPS termination itself; relies on the web server for TLS.
- CLI mode requires outbound network connectivity to the listener.
- Web mode parameter names (`sid`, `upd`, `c`) can be renamed but defaults are predictable.
- No session persistence in web mode; each request is independent.

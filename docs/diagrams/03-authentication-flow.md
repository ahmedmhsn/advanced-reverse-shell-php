# Diagram 3 — Authentication Flow

## Mermaid Diagram

```mermaid
flowchart TD
    Start[("Authentication Start")]:::start

    ModeCheck{"Execution Mode"}:::decision
    Reverse["Reverse Shell Mode<br/>meow_reverse() → meow_handshake()<br/>lines 1323-1321"]:::process
    Web["Web Shell Mode<br/>meow_web() → meow_token_ok()<br/>lines 1365-1389"]:::process

    %% Reverse shell auth path
    TokenSet{"Token configured?<br/>(token !== '')"}:::decision
    SendHash["Send 'MEOW-AUTH ' + SHA-256(token)<br/>over the socket<br/>line 1319"]:::process
    PlaintextNotSent["Plaintext token NEVER<br/>crosses the wire<br/>line 1318 comment"]:::info

    %% Web mode auth path
    WebTokenParam["Read token from $_GET[web_key]<br/>default: 'sid'<br/>lines 1384-1385"]:::process
    TokenCompare["meow_token_ok(given, want)<br/>constant-time comparison<br/>lines 245-254"]:::process

    %% hash_equals path
    HashEquals{"hash_exists?<br/>function_exists('hash_equals')"}:::decision
    UseHashEquals["hash_equals(want, given)<br/>constant-time<br/>line 251"]:::process
    UseSimple["given === want<br/>simple equality<br/>line 253"]:::process

    %% Outcomes
    AuthSuccess["Authentication SUCCESS<br/>proceed to session / execute command"]:::success
    AuthFail["Authentication FAILURE<br/>Web: HTTP 403 + '403\\n'<br/>Reverse: token hash still sent"]:::failure
    NoToken["No token configured<br/>meow_token_ok() returns true<br/>for ANY given value<br/>line 247-248"]:::warning

    StderrWarning["Stderr warning: no token set<br/>(unless quiet mode)<br/>lines 1330-1335"]:::warning

    Start --> ModeCheck
    ModeCheck -->|Reverse| Reverse
    ModeCheck -->|Web| Web

    Reverse --> TokenSet
    TokenSet -->|No| NoToken
    NoToken --> StderrWarning
    StderrWarning --> SendHash
    TokenSet -->|Yes| SendHash
    SendHash --> PlaintextNotSent
    PlaintextNotSent --> AuthSuccess

    Web --> WebTokenParam
    WebTokenParam --> TokenCompare
    TokenCompare --> HashEquals
    HashEquals -->|Yes| UseHashEquals
    HashEquals -->|No| UseSimple
    UseHashEquals -->|true| AuthSuccess
    UseHashEquals -->|false| AuthFail
    UseSimple -->|true| AuthSuccess
    UseSimple -->|false| AuthFail

    TokenCompare -->|empty want = true| AuthSuccess

    classDef start fill:#e1f5e1,stroke:#2e7d32,stroke-width:2px
    classDef process fill:#e3f2fd,stroke:#1565c0,stroke-width:2px
    classDef decision fill:#fff3e0,stroke:#ef6c00,stroke-width:2px
    classDef info fill:#e8eaf6,stroke:#3f51b5,stroke-width:2px
    classDef success fill:#c8e6c9,stroke:#2e7d32,stroke-width:2px
    classDef failure fill:#ffcdd2,stroke:#c62828,stroke-width:2px
    classDef warning fill:#fff9c4,stroke:#f57f17,stroke-width:2px
```

## Explanation of Major Nodes

| Node | Responsibility | Source Reference |
|------|---------------|------------------|
| **Mode Check** | Determines whether authentication happens via reverse-shell handshake or web token check | lines 1323-1411 |
| **Reverse Path** | `meow_handshake()` sends `MEOW-AUTH <sha256(token)>` after connection | lines 1314-1321 |
| **Web Path** | `meow_token_ok()` compares request parameter `$_GET[web_key]` against configured token | lines 1384-1385, 245-254 |
| **Token Set Check** | If token is empty (`''`), authentication is effectively disabled | line 1330 |
| **Send Hash** | Only the SHA-256 hash of the token is transmitted; plaintext never crosses the wire | line 1319 |
| **Token Compare** | `meow_token_ok()` uses `hash_equals()` when available, falls back to `===` | lines 245-254 |
| **No Token** | When `want === ''`, `meow_token_ok()` returns `true` for any `given` value | lines 247-248 |
| **Stderr Warning** | Warning emitted to STDERR when no token and not quiet | lines 1330-1335 |
| **Auth Success** | Reverse: session proceeds. Web: command execution proceeds. | — |
| **Auth Fail** | Web: HTTP 403 response with `403\n`. Reverse: hash still sent (listener decides). | lines 1386-1388 |

## Important Relationships

1. **Two independent auth paths**: Reverse shell sends hash after connecting; web mode compares request param before executing.
2. **Hash only**: The reverse-shell path never transmits the plaintext token — only its SHA-256 hash.
3. **Empty token = no auth**: `meow_token_ok()` short-circuits to `true` when the configured token is empty.
4. **Constant-time comparison**: Uses `hash_equals()` when available to mitigate timing attacks; falls back to `===`.
5. **Web 403 on failure**: Web mode returns HTTP 403 with `403\n` body when token check fails.

## Source-Code References

- Reverse handshake: lines 1314-1321
- Web token check: lines 1384-1389
- Token comparison: lines 245-254
- Empty token short-circuit: lines 247-248
- No-token warning: lines 1330-1335
- Web 403 response: lines 1386-1388

## Security Observations

- **Single shared secret**: One token for all sessions; no per-session or per-user authentication.
- **No replay protection**: The SHA-256 hash is static; an observer who captures it can replay it to authenticate.
- **No mutual authentication**: The shell authenticates to the listener, but the listener does not authenticate to the shell.
- **TLS does not verify peer**: Even with TLS enabled, `verify_peer => false` means the listener's identity is not confirmed.
- **Empty token risk**: If no token is set, anyone who can reach the callback host:port can drive the shell.
- **Stderr warning may leak**: The no-token warning is written to STDERR which may be captured in logs.

## Known Limitations and Uncertainties

- `hash_equals()` availability depends on PHP version (PHP 5.6+).
- Web mode only checks `$_GET[web_key]` for the token; `$_POST` is not checked for the token parameter.
- No rate limiting or lockout on failed authentication attempts.
- No nonce, challenge-response, or binding to a specific connection/session.

# Diagram 10 — Security Trust Boundaries

## Mermaid Diagram

```mermaid
graph TB
    %% Trust zones
    subgraph Attacker["Attacker / Researcher<br/>(Controls listener)"]:::zone_attacker
        Listener["TCP/TLS Listener<br/>Receives callback<br/>Sends commands"]:::entity
    end

    subgraph Network["Network Path<br/>Between target and listener"]:::zone_network
        Traffic["Traffic flow<br/>TCP/TLS callback channel"]:::entity
    end

    subgraph Target["Target Host<br/>Runs meow.php5"]:::zone_target
        PHPProc["PHP Process<br/>meow.php5<br/>Runs as web user / CLI user"]:::entity
        ShellCmd["Shell Commands<br/>executed via primitives<br/>proc_open, shell_exec, etc."]:::entity
        FileSys["File System<br/>Read/write via !cat, !download,<br/>!upload, !cleanup"]:::entity
        EnvVars["Environment<br/>$_SERVER, $_ENV, env command"]:::entity
        ProcList["Process List<br/>ps output via process title"]:::entity
    end

    subgraph TrustBoundary["TRUST BOUNDARY"]:::boundary
    end

    %% Data flows
    Listener -->|Commands + shell input| Traffic
    Traffic -->|TCP/TLS callback| PHPProc
    PHPProc -->|Command output| Traffic
    Traffic -->|Command output| Listener

    PHPProc -->|Executes| ShellCmd
    ShellCmd -->|Reads/writes| FileSys
    ShellCmd -->|Reads| EnvVars
    PHPProc -->|Process title| ProcList

    %% Auth boundaries
    Listener -.->|SHA-256(token) hash<br/>not plaintext| PHPProc
    PHPProc -.->|Validates token hash| Listener

    %% Web boundary
    WebClient["HTTP Client<br/>(Web shell mode)"]:::entity
    WebClient -->|Request with token param| PHPProc
    PHPProc -->|HTTP response| WebClient

    %% What crosses boundaries
    TokenHash["SHA-256(token)<br/>never plaintext"]:::sensitive
    CommandData["Arbitrary commands<br/>files, PHP code"]:::sensitive
    EnvData["Environment variables<br/>$_SERVER, $_ENV"]:::sensitive
    FileData["File contents<br/>read via !cat/!catb"]:::sensitive

    TokenHash -.->|crosses network| Traffic
    CommandData -.->|crosses network| Traffic
    EnvData -.->|exposed via !env| PHPProc
    FileData -.->|exposed via !cat/!catb| PHPProc

    classDef zone_attacker fill:#ffebee,stroke:#c62828,stroke-width:2px
    classDef zone_network fill:#fff3e0,stroke:#ef6c00,stroke-width:2px
    classDef zone_target fill:#e3f2fd,stroke:#1565c0,stroke-width:2px
    classDef boundary fill:#f5f5f5,stroke:#9e9e9e,stroke-width:3px,stroke-dasharray: 5 5
    classDef entity fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px
    classDef sensitive fill:#ffcdd2,stroke:#c62828,stroke-width:2px
```

## Explanation of Trust Zones

| Zone | Description | Entities |
|------|-------------|----------|
| **Attacker / Researcher** | Controls the remote listener; sends commands, receives output | Listener |
| **Network Path** | Network between target host and listener | Traffic (TCP/TLS callback channel) |
| **Target Host** | The system running `meow.php5` | PHP Process, Shell Commands, File System, Environment, Process List |

## Trust Boundaries

### Boundary 1: Network Path (Target ↔ Listener)

| Data Crossing | Direction | Protection | Limitations |
|---------------|-----------|------------|-------------|
| SHA-256(token) hash | Target → Listener | None (hash only, no plaintext) | No replay protection; hash is static |
| Shell commands | Listener → Target | Optional TLS (peer verification disabled) | TLS does not authenticate listener |
| Command output | Target → Listener | Optional TLS (peer verification disabled) | No integrity verification beyond TLS |
| Connection metadata | Both directions | None | Source IP, timing, jitter patterns observable |

### Boundary 2: Web Shell (HTTP Client ↔ Target)

| Data Crossing | Direction | Protection | Limitations |
|---------------|-----------|------------|-------------|
| Token parameter | Client → Target | None (plaintext in request) | Sent in URL or POST body; visible in logs |
| Base64 command | Client → Target | None | Base64 is encoding, not encryption |
| Command output | Target → Client | None (plaintext HTTP response) | No TLS termination by meow.php5 itself |

### Boundary 3: PHP Process → Host Resources

| Resource | Access Method | Restrictions | Limitations |
|----------|--------------|--------------|-------------|
| Shell commands | `proc_open`, `shell_exec`, etc. | `disable_functions` ini | No sandbox; runs with PHP process privileges |
| File system | `file_get_contents`, `file_put_contents`, `unlink` | `open_basedir` ini | No additional path sanitization |
| Environment | `$_SERVER`, `$_ENV`, `env` command | None | All scalar env vars exposed via `!env` |
| Process title | `cli_set_process_title()` | CLI SAPI only; 15-char limit | Cosmetic only; does not hide from determined inspection |

## Sensitive Data

| Data | Sensitivity | Exposure Path | Protection |
|------|-------------|---------------|------------|
| Authentication token | High | SHA-256 hash on wire (reverse); plaintext param (web) | Hash only on wire (reverse); no protection (web) |
| Command data | High | TCP/TLS channel; HTTP request/response | Optional TLS (no peer verification) |
| File contents | High | `!cat`, `!catb`, `!download` responses | None beyond `open_basedir` |
| Environment variables | Medium | `!env` command | None |
| PHP process identity | Low | Process title alias | `cli_set_process_title()` (cosmetic) |

## Attack Surfaces

| Attack Surface | Vector | Mitigation in Code | Residual Risk |
|----------------|--------|-------------------|---------------|
| Callback connection | Network reachability to host:port | None (by design) | Anyone who can reach host:port can connect |
| Authentication | Token knowledge / absence | SHA-256 hash on wire | No token = open access; no replay protection |
| TLS | Peer verification disabled | Encryption only | Vulnerable to active MITM |
| Command execution | `!php`, shell commands | `disable_functions` ini | Full process privileges; no sandbox |
| File access | `!cat`, `!download`, `!upload` | `open_basedir` ini | Arbitrary read/write within `open_basedir` |
| Web shell | HTTP request with token param | Error suppression, header removal | Token in access logs; predictable parameter names |
| Port forwarding | `!fwd --all` | Loopback default | `--all` exposes to all interfaces |
| Environment exposure | `!env` | None | All `$_SERVER`/`$_ENV` scalars disclosed |

## What Is Deliberately NOT in the Code

Per the source header (lines 22-35):
- No source-code obfuscation, packing, or polymorphism
- No timestomping
- No reading, rewriting, or truncating host log files
- No disabling or subverting AV/EDR/syslog
- No self-replication, spray, scanning, or worming
- No browser-credential harvesting
- No local privilege-escalation exploits

## Source-Code References

- Token handling: lines 245-254, 1314-1321, 1384-1385
- TLS config: lines 1281-1288, 1302-1307
- Web headers: lines 1372-1381
- Command execution: lines 308-416
- File operations: lines 930-1022
- Process title: lines 1231-1238
- Environment: lines 1046-1059
- Port forwarding: lines 776-825
- Source header: lines 2-115

## Security Observations

- **No mutual authentication**: The shell authenticates to the listener via token hash, but the listener does not authenticate to the shell.
- **TLS without verification**: Encryption is provided but the listener's identity is not confirmed (`verify_peer => false`).
- **Web token in plaintext**: The token parameter is sent in plaintext over HTTP; visible in access logs and proxy logs.
- **No rate limiting**: Unlimited authentication attempts and command executions.
- **No session binding**: No connection-specific tokens or nonces.
- **Environment disclosure**: `!env` exposes all scalar `$_SERVER` and `$_ENV` variables.
- **Process title is cosmetic**: Does not provide real stealth; truncated to 15 characters on Linux.

## Known Limitations and Uncertainties

- No formal security audit has been performed.
- TLS cipher suite and version are not explicitly configured (uses PHP/OpenSSL defaults).
- No host-based intrusion detection evasion beyond process title and jitter.
- The default token (`X82Wd3t0qasJ87D9hUqPXCl5`) is visible in the source and should be changed before any use.

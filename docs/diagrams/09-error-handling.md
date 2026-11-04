# Diagram 9 — Error Handling and Recovery

## Mermaid Diagram

```mermaid
flowchart TD
    %% Entry points for errors
    Start["Operation Start"]:::start

    %% Error categories
    subgraph Connection["Connection Errors"]
        ConnFail["stream_socket_client() returns false<br/>lines 1298-1299"]:::error
        TLSFail["stream_socket_enable_crypto() fails<br/>lines 1302-1307"]:::error
    end

    subgraph Auth["Authentication Errors"]
        NoToken["Token empty: auth disabled<br/>meow_token_ok() returns true<br/>lines 247-248"]:::error
        Web403["Web: HTTP 403 + '403\\n'<br/>lines 1386-1388"]:::error
    end

    subgraph Exec["Command Execution Errors"]
        NoPrimitive["No usable command primitive<br/>'[!] no usable command primitive'<br/>line 363"]:::error
        ExecFail["Command returns non-zero exit code<br/>lines 1209-1211"]:::error
        PTYFail["proc_open() fails: 'could not spawn shell'<br/>lines 652-654"]:::error
    end

    subgraph File["File Operation Errors"]
        FetchFail["meow_fetch() returns null<br/>lines 583-584"]:::error
        WriteFail["file_put_contents() fails<br/>lines 987, 1015"]:::error
        UnlinkFail["@unlink() fails during cleanup<br/>lines 1098-1102"]:::error
    end

    subgraph Forward["Forwarding Errors"]
        NoPCNTL["pcntl_fork unavailable<br/>lines 778-781"]:::error
        ForkFail["pcntl_fork() returns -1<br/>lines 784-786"]:::error
        BindFail["stream_socket_server() fails<br/>lines 806-808"]:::error
    end

    %% Recovery paths
    Retry["Exponential backoff reconnect<br/>lines 1354-1357"]:::recovery
    Continue["Continue session (error non-fatal)<br/>lines 1198, 1212-1213"]:::recovery
    Degrade["Graceful degradation<br/>try next execution primitive<br/>lines 313-361"]:::recovery
    Fallback["PTY fallback: script → python → shell -i<br/>lines 615-623"]:::recovery
    Skip["Skip failed operation<br/>continue to next ledger entry<br/>lines 1091-1102"]:::recovery

    %% Outcomes
    Reconnect["Reconnect to listener"]:::outcome
    SessionEnd["Session ends"]:::outcome
    Prompt["Reprint prompt"]:::outcome

    Start --> Connection
    Start --> Auth
    Start --> Exec
    Start --> File
    Start --> Forward

    ConnFail --> Retry
    TLSFail --> Retry
    Retry -->|retry enabled| Reconnect
    Retry -->|retry disabled/max| SessionEnd

    NoToken --> Continue
    Web403 --> SessionEnd

    NoPrimitive --> Prompt
    ExecFail --> Prompt
    PTYFail --> Continue

    FetchFail --> Continue
    WriteFail --> Continue
    UnlinkFail --> Skip

    NoPCNTL --> Continue
    ForkFail --> Continue
    BindFail -->|child exit(1)| SessionEnd

    Degrade --> Prompt
    Fallback --> Prompt

    classDef start fill:#e1f5e1,stroke:#2e7d32,stroke-width:2px
    classDef error fill:#ffcdd2,stroke:#c62828,stroke-width:2px
    classDef recovery fill:#c8e6c9,stroke:#2e7d32,stroke-width:2px
    classDef outcome fill:#e3f2fd,stroke:#1565c0,stroke-width:2px
```

## Explanation of Error Categories

### Connection Errors

| Error | Condition | Handling | Source |
|-------|-----------|----------|--------|
| Connection failure | `stream_socket_client()` returns `false` | Retry with exponential backoff + jitter | lines 1298-1299, 1354-1357 |
| TLS upgrade failure | `stream_socket_enable_crypto()` returns non-true | Close socket, retry connection | lines 1302-1307 |

### Authentication Errors

| Error | Condition | Handling | Source |
|-------|-----------|----------|--------|
| No token | `token === ''` | Auth disabled; `meow_token_ok()` returns true for any value; stderr warning unless quiet | lines 247-248, 1330-1335 |
| Web 403 | Token mismatch in web mode | HTTP 403 response + `403\n`; return early | lines 1386-1388 |

### Command Execution Errors

| Error | Condition | Handling | Source |
|-------|-----------|----------|--------|
| No primitive | All execution functions disabled | Returns `[!] no usable command primitive` with exit code 1 | line 363 |
| Non-zero exit | Command returns exit code ≠ 0 | Reports `[exit %d]` but continues session | lines 1209-1211 |
| PTY spawn failure | `proc_open()` returns non-resource | Prints `[-] could not spawn a shell process`, returns to session | lines 652-654 |

### File Operation Errors

| Error | Condition | Handling | Source |
|-------|-----------|----------|--------|
| Fetch failure | `meow_fetch()` returns `null` | Prints `[-] fetch failed: <url>`; continues session | lines 583-584, 983-985 |
| Write failure | `file_put_contents()` returns `false` | Prints `[-] write failed: <path>`; continues session | lines 987-989, 1015-1017 |
| Unlink failure | `@unlink()` returns `false` | Prints `FAILED`; continues to next ledger entry | lines 1098-1102 |

### Forwarding Errors

| Error | Condition | Handling | Source |
|-------|-----------|----------|--------|
| No pcntl | `pcntl_fork` not available | Prints `[!] pcntl unavailable; cannot fork a forwarder`; returns | lines 778-781 |
| Fork failure | `pcntl_fork()` returns `-1` | Prints `[!] fork failed`; returns | lines 784-786 |
| Bind failure | `stream_socket_server()` returns `false` | Child process `exit(1)` | lines 806-808 |

## Recovery Mechanisms

| Mechanism | Description | Source |
|-----------|-------------|--------|
| **Exponential backoff** | Delay = `min(60, retry_delay * 2^min(tries,4))`, then jittered; `retry_max=0` = forever | lines 1354-1357 |
| **Primitive fallback** | `meow_raw_exec()` tries `proc_open` → `shell_exec` → `passthru` → `system` → `exec` → `popen` | lines 313-361 |
| **PTY fallback** | `script` → `python3`/`python pty` → `<shell> -i` | lines 615-623 |
| **Non-fatal errors** | Most errors print a message and return to the prompt; session continues | lines 1198, 1212-1213 |
| **Cleanup skip** | Failed unlink continues to next ledger entry | lines 1091-1102 |

## Important Relationships

1. **Connection errors → Retry**: Only connection-level errors trigger reconnection; command/file errors do not.
2. **Execution errors → Continue**: Command execution errors are non-fatal; the session continues with the prompt reprinted.
3. **Web 403 → End**: Web mode authentication failure terminates the request immediately.
4. **PTY failure → Continue**: PTY spawn failure returns to the line-at-a-time session.
5. **Forward bind failure → Child exits**: The detached forwarder child exits with code 1; parent is unaffected.

## Source-Code References

- Connect failure: lines 1298-1299
- TLS failure: lines 1302-1307
- Retry/backoff: lines 1347-1358
- No token: lines 247-248, 1330-1335
- Web 403: lines 1386-1388
- No primitive: line 363
- Exec exit code: lines 1209-1211
- PTY fail: lines 652-654
- Fetch fail: lines 583-584, 983-985
- Write fail: lines 987-989, 1015-1017
- Unlink fail: lines 1098-1102
- No pcntl: lines 778-781
- Fork fail: lines 784-786
- Bind fail: lines 806-808
- Primitive fallback: lines 313-361
- PTY fallback: lines 615-623

## Security Observations

- **Errors are suppressed in web mode**: `display_errors=0`, `log_errors=0` prevents error details from leaking via HTTP responses.
- **No error logging to file**: `log_errors=0` means errors are not recorded server-side in web mode.
- **Silent failures**: Many operations use `@` error suppression; failures are only visible via return values and printed messages.
- **Reconnect increases exposure**: Automatic reconnection means the shell remains available even after listener restarts.
- **No stack traces**: PHP errors/warnings are suppressed; no diagnostic information is exposed to the client.

## Known Limitations and Uncertainties

- `retry_max = 0` means reconnection attempts continue indefinitely with no upper bound.
- Jitter is applied to backoff but the base delay grows exponentially (capped at 60s).
- No distinction between transient and permanent connection failures.
- Cleanup does not roll back partially completed operations (e.g., a file write that partially succeeded).

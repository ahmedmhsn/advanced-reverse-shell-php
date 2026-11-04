# Diagram 6 — Internal Command Architecture

## Mermaid Diagram

```mermaid
flowchart TD
    Input["Client Input Line<br/>(from socket)"]:::input

    IsBang{"Starts with '!'?"}:::decision
    IsDoubleBang{"Starts with '!!'?"}:::decision

    ShellExec["Execute as shell command<br/>meow_exec()<br/>lines 1207-1212"]:::shell
    DoubleBangEscaped["Strip one '!', prefix with '\\'<br/>'$line = \\\\' . substr($line, 1)'<br/>line 1204"]:::shell

    BuiltinDispatch["meow_builtin()<br/>Command dispatcher<br/>lines 868-1163"]:::dispatch

    %% Command categories
    subgraph Info["Information Commands"]
        InfoHelp["!help<br/>List built-ins<br/>line 879"]
        InfoInfo["!info<br/>Host + PHP report<br/>line 889"]
        InfoPerm["!perm<br/>Exec primitives + restrictions<br/>line 893"]
        InfoEnv["!env<br/>Environment variables<br/>line 1046"]
    end

    subgraph Navigation["Navigation Commands"]
        NavPwd["!pwd<br/>Print working directory<br/>line 897"]
        NavCd["!cd <dir><br/>Change directory<br/>line 901"]
        NavLs["!ls [dir]<br/>Directory listing<br/>line 923"]
    end

    subgraph FileOps["File Operations (read-only)"]
        FileCat["!cat <file><br/>Text file, 1 MiB cap<br/>line 930"]
        FileCatB["!catb <file><br/>Base64, binary-safe, 10 MiB cap<br/>line 948"]
    end

    subgraph FileTransfer["File Transfer (read/write)"]
        FtDownload["!download <url> [path]<br/>Remote → host<br/>line 974"]
        FtUpload["!upload <path><br/>Host ← base64<br/>line 996"]
    end

    subgraph System["System Commands"]
        SysUpgrade["!upgrade<br/>PTY session<br/>line 886"]
        SysPhp["!php <code><br/>Eval PHP in process<br/>line 1062"]
        SysFwd["!fwd [--all] <lport> <rhost> <rport><br/>TCP forwarder<br/>line 1024"]
    end

    subgraph Session["Session Control"]
        SessExit["!exit<br/>Close session<br/>line 883"]
        SessCleanup["!cleanup [--self]<br/>Revert tracked writes/forwards<br/>line 1081"]
        SessSelfDestruct["!selfdestruct<br/>Delete shell file + exit<br/>line 1152"]
    end

    %% Dispatch returns
    BuiltinDispatch --> ReturnContinue["Return 'continue'<br/>Reprint prompt"]:::dispatch
    BuiltinDispatch --> ReturnExit["Return 'exit'<br/>End session"]:::dispatch
    BuiltinDispatch --> ReturnUpgrade["Return 'upgrade'<br/>Enter PTY mode"]:::dispatch

    %% Flow
    Input --> IsBang
    IsBang -->|No| ShellExec
    IsBang -->|Yes| IsDoubleBang
    IsDoubleBang -->|Yes| DoubleBangEscaped
    DoubleBangEscaped --> ShellExec
    IsDoubleBang -->|No| BuiltinDispatch

    BuiltinDispatch --> Info
    BuiltinDispatch --> Navigation
    BuiltinDispatch --> FileOps
    BuiltinDispatch --> FileTransfer
    BuiltinDispatch --> System
    BuiltinDispatch --> Session

    classDef input fill:#e1f5e1,stroke:#2e7d32,stroke-width:2px
    classDef decision fill:#fff3e0,stroke:#ef6c00,stroke-width:2px
    classDef shell fill:#f3e5f5,stroke:#7b1fa2,stroke-width:2px
    classDef dispatch fill:#e8eaf6,stroke:#3f51b5,stroke-width:2px
    classDef info fill:#e0f2f1,stroke:#00695c,stroke-width:2px
    classDef navigation fill:#f1f8e9,stroke:#33691e,stroke-width:2px
    classDef fileops fill:#fff8e1,stroke:#f57f17,stroke-width:2px
    classDef filetransfer fill:#ffebee,stroke:#c62828,stroke-width:2px
    classDef system fill:#fce4ec,stroke:#880e4f,stroke-width:2px
    classDef session fill:#f5f5f5,stroke:#9e9e9e,stroke-width:2px
```

## Explanation of Major Nodes

| Node | Responsibility | Source Reference |
|------|---------------|------------------|
| **Input** | Raw line read from socket via `fgets($sock, 8192)` | line 1186 |
| **IsBang** | Decision: does line start with `!`? | line 1194 |
| **IsDoubleBang** | Decision: does line start with `!!`? | line 1201 |
| **ShellExec** | Regular command executed via `meow_exec()` | lines 1207-1212 |
| **DoubleBangEscaped** | `!!<cmd>` → `\<cmd>` so `!` reaches shell literally | line 1204 |
| **BuiltinDispatch** | `meow_builtin()` parses and dispatches `!` commands | lines 868-1163 |

### Command Categories

**Information Commands** (read-only host/PHP reconnaissance):
- `!help` — lists all built-in commands (line 879)
- `!info` — host + PHP capability report (line 889)
- `!perm` — exec primitives, `disable_functions`, `open_basedir` (line 893)
- `!env` — dumps `$_SERVER`, `$_ENV`, and `env` command output (line 1046)

**Navigation Commands** (working directory):
- `!pwd` — prints current working directory (line 897)
- `!cd <dir>` — changes cwd; no arg resets to default (line 901)
- `!ls [dir]` — runs `ls -la` with 15-second timeout (line 923)

**File Operations** (read-only):
- `!cat <file>` — prints text file, 1 MiB cap, suggests `!catb` for larger (line 930)
- `!catb <file>` — base64-encoded binary-safe read, 10 MiB cap (line 948)

**File Transfer** (read/write):
- `!download <url> [path]` — fetches remote file via `meow_fetch()`, writes via `file_put_contents()`, tracked in ledger (line 974)
- `!upload <path>` — receives base64 lines terminated by `.`, 50 MiB ceiling, strict base64 decode, tracked in ledger (line 996)

**System Commands**:
- `!upgrade` — enters interactive PTY session (line 886)
- `!php <code>` — `eval($code . ';')` with output buffering and exception handling (line 1062)
- `!fwd [--all] <lport> <rhost> <rport>` — forked TCP port forwarder via `pcntl_fork` (line 1024)

**Session Control**:
- `!exit` — returns 'exit', ending the session (line 883)
- `!cleanup [--self]` — unlinks tracked files, kills tracked forwards, resets cwd; `--self` also deletes shell file (line 1081)
- `!selfdestruct` — deletes shell file and exits (line 1152)

## Dispatch Return Values

| Return | Meaning | Source |
|--------|---------|--------|
| `'continue'` | Command handled, reprint prompt | lines 881, 891, 895, 899, 921, 928, 946, 972, 994, 1022, 1044, 1079, 1149, 1162 |
| `'exit'` | End the session | line 884 |
| `'upgrade'` | Enter PTY mode | line 887 |

## Source-Code References

- Input reading: line 1186
- Bang detection: line 1194
- Double-bang handling: lines 1201-1204
- Shell execution: lines 1207-1212
- Built-in dispatcher: lines 868-1163
- Individual commands: lines 879-1159 (see above table)

## Security Observations

- **`!php <code>`**: Arbitrary PHP evaluation with full process privileges — highest risk command.
- **`!download`/`!upload`**: Arbitrary file write capability, subject to `open_basedir` and filesystem permissions.
- **`!cat`/`!catb`**: Arbitrary file read capability, subject to `open_basedir` and filesystem permissions.
- **`!fwd`**: Can expose internal network services via `--all` (binds to 0.0.0.0).
- **`!selfdestruct`/`!cleanup --self`**: Irreversible self-deletion of the shell file.
- **No command filtering**: Any shell command can be executed; no allowlist/denylist beyond `disable_functions`.
- **`!!<cmd>` escape**: Allows `!` as a literal shell command name, bypassing built-in dispatch.

## Known Limitations and Uncertainties

- `!cat` refuses files >1 MiB; `!catb` refuses >10 MiB; `!upload` caps base64 input at 50 MiB.
- `!download` URL validation requires `FILTER_VALIDATE_URL` or `http` prefix.
- `!fwd` requires `pcntl_fork`; unavailable without the `pcntl` extension.
- `!upgrade` unavailable on Windows.
- File size caps are hardcoded constants (1 MiB, 10 MiB, 50 MiB).
- `!php` eval has no sandbox; exceptions are caught but the process continues.

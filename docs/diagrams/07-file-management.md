# Diagram 7 — File Management Architecture

## Mermaid Diagram

```mermaid
graph TB
    %% External sources
    Remote_URL["Remote URL<br/>(!download source)"]:::external
    Client_Socket["Client Socket<br/>(!upload source)"]:::external
    Local_File["Local File System<br/>(target of read/write)"]:::external

    %% Entry points
    DownloadCmd["!download <url> [path]<br/>line 974"]:::cmd
    UploadCmd["!upload <path><br/>line 996"]:::cmd
    CatCmd["!cat <file><br/>line 930"]:::cmd
    CatBCmd["!catb <file><br/>line 948"]:::cmd
    CleanupCmd["!cleanup [--self]<br/>line 1081"]:::cmd
    SelfDestructCmd["!selfdestruct<br/>line 1152"]:::cmd

    %% Download path
    Fetch["meow_fetch($url)<br/>lines 526-584"]:::process
    URLFopen["file_get_contents()<br/>allow_url_fopen<br/>lines 532-545"]:::process
    CurlExt["curl_init()<br/>cURL extension<br/>lines 547-565"]:::process
    CurlBin["curl/wget binary<br/>via meow_raw_exec()<br/>lines 567-581"]:::process
    WriteDownload["file_put_contents($dest, $data)<br/>line 987"]:::process
    NoteDownload["meow_note(['write', $dest, $bytes])<br/>line 991"]:::process

    %% Upload path
    ReadB64["Read base64 lines from socket<br/>'.' terminator<br/>lines 1004-1009"]:::process
    DecodeB64["base64_decode($b64, false)<br/>strict mode<br/>line 1010"]:::process
    WriteUpload["file_put_contents($path, $data)<br/>line 1015"]:::process
    NoteUpload["meow_note(['write', $path, $bytes])<br/>line 1019"]:::process

    %% Read path
    ReadCat["file_get_contents($f)<br/>1 MiB cap<br/>lines 940-944"]:::process
    ReadCatB["file_get_contents($f)<br/>10 MiB cap + base64<br/>lines 964-970"]:::process

    %% Cleanup path
    Ledger["meow_effects()<br/>Side-effect ledger<br/>lines 1267-1270"]:::process
    IterateWrites["Iterate ['write', $path, $bytes]<br/>entries"]:::process
    Unlink["@unlink($path)<br/>line 1098"]:::process
    ResetCwd["$meowCwd = null<br/>force re-detection<br/>line 1134"]:::process

    %% Self-destruct
    UnlinkSelf["@unlink(__FILE__)<br/>line 1157"]:::process

    %% Access boundaries
    OpenBasedir["open_basedir restriction<br/>(PHP ini setting)"]:::boundary
    FilePerms["Filesystem permissions<br/>(PHP process user)"]:::boundary

    %% Relationships - Download
    Remote_URL --> DownloadCmd
    DownloadCmd --> Fetch
    Fetch --> URLFopen
    Fetch --> CurlExt
    Fetch --> CurlBin
    Fetch --> WriteDownload
    WriteDownload --> NoteDownload
    NoteDownload --> Ledger
    Local_File -.->|target| WriteDownload

    %% Relationships - Upload
    Client_Socket --> UploadCmd
    UploadCmd --> ReadB64
    ReadB64 --> DecodeB64
    DecodeB64 --> WriteUpload
    WriteUpload --> NoteUpload
    NoteUpload --> Ledger
    Local_File -.->|target| WriteUpload

    %% Relationships - Read
    Local_File -.->|source| CatCmd
    Local_File -.->|source| CatBCmd
    CatCmd --> ReadCat
    CatBCmd --> ReadCatB

    %% Relationships - Cleanup
    CleanupCmd --> Ledger
    Ledger --> IterateWrites
    IterateWrites --> Unlink
    IterateWrites --> ResetCwd

    %% Relationships - Self-destruct
    SelfDestructCmd --> UnlinkSelf

    %% Access boundaries apply to all file operations
    WriteDownload -.->|subject to| OpenBasedir
    WriteUpload -.->|subject to| OpenBasedir
    ReadCat -.->|subject to| OpenBasedir
    ReadCatB -.->|subject to| OpenBasedir
    Unlink -.->|subject to| OpenBasedir
    WriteDownload -.->|subject to| FilePerms
    WriteUpload -.->|subject to| FilePerms
    ReadCat -.->|subject to| FilePerms
    ReadCatB -.->|subject to| FilePerms
    UnlinkSelf -.->|subject to| FilePerms

    classDef external fill:#e1f5e1,stroke:#2e7d32,stroke-width:2px
    classDef cmd fill:#fff3e0,stroke:#ef6c00,stroke-width:2px
    classDef process fill:#e3f2fd,stroke:#1565c0,stroke-width:2px
    classDef boundary fill:#ffebee,stroke:#c62828,stroke-width:2px
```

## Explanation of Major Nodes

| Node | Responsibility | Source Reference |
|------|---------------|------------------|
| **!download** | Fetches remote URL and writes to local path; records in ledger | line 974 |
| **!upload** | Receives base64 from socket, decodes, writes to local path; records in ledger | line 996 |
| **!cat** | Reads and prints text file (1 MiB cap) | line 930 |
| **!catb** | Reads and base64-encodes file (10 MiB cap, binary-safe) | line 948 |
| **!cleanup** | Unlinks tracked files, kills tracked forwards, resets cwd | line 1081 |
| **!selfdestruct** | Deletes shell file and exits | line 1152 |
| **meow_fetch()** | Retrieves remote content via URL fopen, cURL, or curl/wget binary | lines 526-584 |
| **file_put_contents()** | Writes data to local file (used by download/upload) | lines 987, 1015 |
| **base64_decode()** | Strict base64 decoding for upload (line 1010) | line 1010 |
| **meow_note()** | Records `['write', $path, $bytes]` in side-effect ledger | lines 991, 1019, 1262-1265 |
| **meow_effects()** | Returns all tracked side effects for cleanup | lines 1267-1270 |
| **@unlink()** | Deletes file during cleanup or self-destruct | lines 1098, 1157 |

## File Size Caps

| Command | Cap | Behavior at cap | Source |
|---------|-----|-----------------|--------|
| `!cat` | 1 MiB (1,048,576 bytes) | Suggests `!catb`; refuses to print | line 940-942 |
| `!catb` | 10 MiB (10,485,760 bytes) | Suggests `!download`; refuses to read | line 960-962 |
| `!upload` | 50 MiB base64 input | Breaks reading loop | line 1008 |

## Ledger Entries

| Entry Type | Format | Recorded By |
|------------|--------|-------------|
| File write | `['write', $path, $bytes]` | `!download` (line 991), `!upload` (line 1019) |
| Port forward | `['fwd', $pid, $bind, $lport, $rhost, $rport]` | `!fwd` → `meow_forward()` (line 789) |

## Access Boundaries

| Boundary | Description | Enforced By |
|----------|-------------|-------------|
| **`open_basedir`** | PHP ini setting restricting file access paths | PHP engine (checked implicitly by `file_get_contents`, `file_put_contents`, `unlink`) |
| **Filesystem permissions** | PHP process user's read/write/unlink permissions | OS filesystem |

## Important Relationships

1. **Download → Fetch → Write → Ledger**: Remote content is fetched, written to disk, and recorded for cleanup.
2. **Upload → Read → Decode → Write → Ledger**: Base64 is read from socket, decoded, written, and recorded.
3. **Read commands → Local file**: `!cat`/`!catb` read local files but do NOT record in ledger (read-only).
4. **Cleanup → Ledger → Unlink**: Only paths recorded by `!download`/`!upload` are eligible for cleanup.
5. **Self-destruct → Unlink(__FILE__)**: Independent of ledger; deletes the shell file itself.

## Source-Code References

- Download: lines 974-994
- Upload: lines 996-1022
- Cat: lines 930-946
- CatB: lines 948-972
- Fetch: lines 526-584
- Cleanup: lines 1081-1149
- Self-destruct: lines 1152-1159
- Ledger: lines 1262-1270

## Security Observations

- **Arbitrary file write**: `!download` and `!upload` can write any path the PHP process can write to, subject to `open_basedir`.
- **Arbitrary file read**: `!cat`/`!catb` can read any file the PHP process can read.
- **No path traversal guards**: Beyond `open_basedir`, no additional path sanitization is performed.
- **SSL verification disabled in fetch**: `verify_peer => false`, `allow_self_signed => true` — remote downloads are not authenticated.
- **Cleanup is ledger-limited**: Files written outside `!download`/`!upload` (e.g., shell redirects, temp files) are NOT tracked and will not be removed by `!cleanup`.
- **Self-deletion is irreversible**: `!selfdestruct` and `!cleanup --self` permanently remove the shell file.
- **Upload strict base64**: `base64_decode($b64, false)` with strict mode rejects malformed input.

## Known Limitations and Uncertainties

- `open_basedir` enforcement depends on PHP configuration; not enforced by the code itself.
- File size caps are hardcoded; no configuration option to adjust them.
- `!download` follows up to 5 redirects (line 534).
- `!fetch` timeout is 30 seconds (lines 534, 554).
- Cleanup does not verify file ownership before unlinking tracked paths.

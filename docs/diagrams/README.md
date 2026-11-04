# Architecture Diagrams

This directory contains Mermaid-based architecture diagrams for the **meow.php5** project (v2.1), a single-file, zero-dependency PHP reverse shell and web shell.

## Diagrams

| # | File | Description |
|---|------|-------------|
| 1 | `01-system-architecture.md` | Major components and their relationships |
| 2 | `02-execution-flow.md` | Program lifecycle from entry point through shutdown |
| 3 | `03-authentication-flow.md` | Authentication decision process and security limitations |
| 4 | `04-cli-vs-http.md` | CLI vs HTTP execution modes comparison |
| 5 | `05-session-lifecycle.md` | Session states, connections, error handling, termination |
| 6 | `06-command-architecture.md` | Command categories and dispatch mechanism |
| 7 | `07-file-management.md` | File-handling components, access boundaries, security risks |
| 8 | `08-process-management.md` | Process creation, monitoring, and cleanup relationships |
| 9 | `09-error-handling.md` | Error categories, handling paths, recovery behavior |
| 10 | `10-security-boundaries.md` | Trust boundaries, exposed interfaces, attack surfaces |

## Source Integrity

The PHP source file `meow.php5` has **not been modified**. All diagrams are based strictly on reading the source code.

SHA-256 of `meow.php5`:
`e54852795316f5b161c78d52f192d0e709a4c935bc355d0b28346720dcc99493`

## Verified vs Inferred Behavior

All diagrams describe behavior inferred from reading the source code. No dynamic execution has been performed. Line-number references point to `meow.php5`.

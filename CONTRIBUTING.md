# Contributing

## Scope of this repository

This repository contains documentation and repository metadata for the **meow.php5** project — a single-file, zero-dependency PHP reverse shell and web shell.

**The PHP source file (`meow.php5`) must not be modified.** Contributions are limited to:

- Documentation files (README, docs/*.md, CHANGELOG, CONTRIBUTING)
- `.gitignore`
- Repository metadata

Do not submit pull requests that:
- Edit `meow.php5` or any other PHP source file
- Refactor, optimize, or rename source files
- Add, remove, or change code
- Change default values, authentication behavior, or execution behavior

## How to report issues

If you find an inaccuracy in the documentation (for example, a behavior described here that does not match the actual source), open an issue with:

1. The file and line(s) where the discrepancy appears
2. The expected behavior based on reading the source
3. The actual behavior described in the documentation

Do not report issues about the reverse shell's offensive capabilities — those are intentional by design. Do not request features that add obfuscation, log tampering, AV/EDR subversion, self-replication, credential harvesting, privilege escalation, or similar capabilities. The source explicitly excludes these.

## Documentation contributions

Documentation must be based strictly on the actual implementation:

- Never invent features, test results, compatibility claims, security guarantees, testimonials, or performance measurements.
- Clearly distinguish verified behavior (from reading the source) from behavior inferred by reading the source.
- Do not add instructions that facilitate unauthorized deployment or stealth.
- Document lab-only, authorized-use execution. Do not add instructions that facilitate unauthorized deployment.

When contributing documentation, verify claims against `meow.php5` source before submitting.

## Testing expectations

No automated test suite is included. The reverse shell must not be executed except in an explicitly authorized, isolated lab environment. Do not fabricate test results. Any claims about test results or execution behavior must be based on reading the source, not on running it.

## Code of conduct

This is a security-research tool. Contributors must agree to use it only:

- On systems they own, or
- On systems for which they have explicit, written authorization to test, and
- In an isolated lab environment.

Unauthorized deployment or use is prohibited.

## License

Based on pentestmonkey's php-reverse-shell (GPLv2), rewritten as a session-oriented shell. Review the source header and any accompanying license terms before contributing.

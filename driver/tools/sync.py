"""phpbm sync: copies a project's sources (to /sdcard/app) and its microSD assets to the card over the serial console.

The firmware changes only the folders the host declares (the managed roots): app for the sources, plus each microSD
asset folder. The protocol is described in host/components/phpbm/sync.c.
"""

import argparse
import hashlib
import json
import os
import sys
import time

HELLO = b"PHPBM-SYNC 2\n"
CODE_ROOT = "app"  # the sources' folder on the card
CHUNK = 4096
EXCLUDED = {".env"}
BAD_CHARS = set("\\:\"*<>?|")
IDLE = 120.0
MARGIN = 64 * 1024
BASE_BAUD = 115200
BAUDS = (921600, 460800)
BAUD_FALLBACK = 1.2  # the firmware goes back to the old rate 1 s after its OK without a PING
RESERVED = (".part", ".old")  # the firmware's transfer files
STOPPED_NO_ENTRY = " on the microSD card (run phpbm sync)"
ASSETS_ON_CARD = ", assets on microsd"  # the storage line of a firmware with the code in flash and assets on the card
NO_CARD = "phpbm: microSD not mounted"


class SyncError(Exception):
    pass


def valid_path(path):
    if not path or len(path.encode("utf-8")) >= 256 or path.startswith("/") or path.endswith("/"):
        return False
    if any(not 0x20 <= ord(c) < 0x7f or c in BAD_CHARS for c in path):
        return False
    return all(seg not in ("", ".", "..") and seg[-1] not in ". " for seg in path.split("/"))


def file_sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(65536), b""):
            digest.update(block)
    return digest.hexdigest()


def scan(src, exclude=()):
    """Maps every file under src (relative '/' path) to (sha256, size)."""
    files = {}
    skip = {os.path.normcase(os.path.abspath(e)) for e in exclude}
    seen = {}
    for root, dirs, names in os.walk(src):
        dirs.sort()
        for name in sorted(names):
            full = os.path.join(root, name)
            if name in EXCLUDED or os.path.normcase(os.path.abspath(full)) in skip:
                continue
            rel = os.path.relpath(full, src).replace(os.sep, "/")
            if not valid_path(rel) or rel.casefold().endswith(RESERVED):
                raise SyncError("cannot sync {}: unsupported file name (use printable ASCII, no trailing '.' or space, none of \\ : \" * < > ? |, no .part or .old ending)".format(rel))
            key = rel.casefold()
            if key in seen:
                raise SyncError("cannot sync {} and {}: the card's file names are case-insensitive".format(seen[key], rel))
            seen[key] = rel
            files[rel] = (file_sha256(full), os.path.getsize(full))
    return files


def parse_entry(line):
    """'F <sha256> <size> <path>' -> (path, (sha256, size)), None for any other line."""
    parts = line.split(" ", 3)
    if len(parts) != 4 or parts[0] != "F" or len(parts[1]) != 64 or not parts[2].isdigit():
        return None
    return parts[3], (parts[1], int(parts[2]))


def in_roots(path, roots):
    """True when path is a file inside one of the managed roots."""
    return any(path.startswith(root + "/") for root in roots)


def plan(local, remote, roots):
    """The files to delete from the card (only inside the managed roots) and the ones to send, both sorted."""
    deletes = sorted(p for p in remote if p not in local and valid_path(p) and in_roots(p, roots))
    puts = sorted(p for p in local if remote.get(p) != tuple(local[p][:2]))
    return deletes, puts


def assets_of(assets):
    """The roots and files of an assets file; an empty files list is an empty object (PHP's json_encode of [])."""
    if assets is None:
        return [], {}
    bad = SyncError("malformed assets file: expected {\"roots\": [folder, ...], \"files\": {card path: local file}}")
    if not isinstance(assets, dict):
        raise bad
    roots = assets.get("roots") or []
    files = assets.get("files") or {}
    if not isinstance(roots, list) or not isinstance(files, dict) or not all(isinstance(r, str) for r in roots):
        raise bad
    if not all(isinstance(p, str) and isinstance(f, str) for p, f in files.items()):
        raise bad
    return roots, files


def collect(src=None, exclude=(), assets=None):
    """The files to have on the card (card path -> (sha256, size, local file)) and the managed roots.

    src: the sources, which go to app/; assets: {"roots": [folder, ...], "files": {card path: local file}}.
    """
    asset_roots, asset_files = assets_of(assets)
    roots = []
    local = {}
    if src is not None:
        roots.append(CODE_ROOT)
        for rel, (sha, size) in scan(src, exclude).items():
            local[CODE_ROOT + "/" + rel] = (sha, size, os.path.join(src, *rel.split("/")))
    for root in asset_roots:
        if not valid_path(root):
            raise SyncError("cannot sync the assets in {}: unsupported folder name".format(root))
        for other in roots:
            a, b = root.casefold(), other.casefold()  # the card's names are case-insensitive
            if a == b or a.startswith(b + "/") or b.startswith(a + "/"):
                if other == CODE_ROOT and src is not None:
                    raise SyncError("cannot sync the assets in {}: it is inside the sources' folder /sdcard/{} on the card".format(root, CODE_ROOT))
                raise SyncError("cannot sync the assets in {} and {}: the folders overlap".format(other, root))
        roots.append(root)
    seen = {p.casefold(): p for p in local}
    for path, file in sorted(asset_files.items()):
        if not valid_path(path) or path.casefold().endswith(RESERVED):
            raise SyncError("cannot sync {}: unsupported file name (use printable ASCII, no trailing '.' or space, none of \\ : \" * < > ? |, no .part or .old ending)".format(path))
        if not in_roots(path, roots[1:] if src is not None else roots):
            raise SyncError("cannot sync {}: it is outside the managed folders".format(path))
        key = path.casefold()
        if key in seen:
            raise SyncError("cannot sync {} and {}: the card's file names are case-insensitive".format(seen[key], path))
        seen[key] = path
        if not os.path.isfile(file):
            raise SyncError("cannot sync {}: no asset file {}".format(path, file))
        local[path] = (file_sha256(file), os.path.getsize(file), file)
    return local, roots


def chunks(data):
    for i in range(0, len(data), CHUNK):
        part = data[i:i + CHUNK]
        yield str(len(part)).encode() + b"\n" + part


def summary(files, sent, elapsed, deleted, unchanged, free):
    free_mb = free // (1024 * 1024)
    if files == 0:
        return "up to date: {} unchanged, {} deleted; {} MB free on the card".format(unchanged, deleted, free_mb)
    rate = sent / 1024 / elapsed if elapsed > 0 else 0.0
    return "sent {} file(s), {:.1f} KB in {:.1f} s ({:.0f} KB/s); {} unchanged, {} deleted; {} MB free on the card".format(
        files, sent / 1024, elapsed, rate, unchanged, deleted, free_mb)


class Link:
    def __init__(self, port):
        import serial

        self.serial_error = serial.SerialTimeoutException
        self.name = port
        self.port = serial.Serial()
        self.port.port = port
        self.port.baudrate = BASE_BAUD
        self.port.timeout = 0.05
        self.port.write_timeout = 0.2
        self.port.dtr = False
        self.port.rts = False
        self.port.open()
        self.pending = b""

    def reset(self):
        self.port.dtr = False
        self.port.rts = True
        time.sleep(0.12)
        self.port.rts = False
        self.port.reset_input_buffer()
        self.pending = b""

    def set_baud(self, baud):
        self.port.baudrate = baud
        self.pending = b""

    def ping(self, timeout):
        """Sends PING until the firmware answers PONG; False after timeout seconds."""
        time.sleep(0.02)
        self.port.reset_input_buffer()
        self.pending = b""
        end = time.monotonic() + timeout
        next_ping = 0.0
        while time.monotonic() < end:
            if time.monotonic() >= next_ping:
                self.try_send(b"PING\n")
                next_ping = time.monotonic() + 0.1
            if self.line(0.02) == "PONG":
                self.flush_input()
                return True
        return False

    def flush_input(self):
        time.sleep(0.1)
        self.port.reset_input_buffer()
        self.pending = b""

    def send(self, data, timeout=10.0):
        self.port.write_timeout = timeout
        self.port.write(data)
        self.port.flush()

    def try_send(self, data):
        self.port.write_timeout = 0.05
        try:
            self.port.write(data)
        except self.serial_error:
            pass

    def line(self, timeout):
        """The next line (without the ending), or None after timeout seconds."""
        end = time.monotonic() + timeout
        while b"\n" not in self.pending:
            if time.monotonic() >= end:
                return None
            self.pending += self.port.read(max(1, min(self.port.in_waiting, 4096)))
        raw, self.pending = self.pending.split(b"\n", 1)
        return raw.rstrip(b"\r").decode("utf-8", "replace")

    def reply(self, timeout=15.0):
        """The firmware's next answer (OK, ERR, DONE, BYE), skipping log lines."""
        end = time.monotonic() + timeout
        while True:
            line = self.line(max(0.0, end - time.monotonic()))
            if line is None:
                raise SyncError("the board stopped answering")
            if line in ("OK", "BYE") or line.startswith(("ERR ", "DONE ")):
                return line

    def close(self):
        self.port.close()


def boot_line(line, port, code=True):
    """True when the hello should go on through the next boot; raises when the board cannot sync.

    code: the sources go to the card too, which needs a firmware with the microsd storage.
    """
    if line.startswith("phpbm: storage "):
        storage = line[15:].strip()
        if storage.endswith(ASSETS_ON_CARD) and code:
            raise SyncError("the firmware on {} keeps the sources in flash (storage {}): flash it first".format(port, storage[:-len(ASSETS_ON_CARD)]))
        if storage != "microsd" and not storage.endswith(ASSETS_ON_CARD):
            raise SyncError("the firmware on {} does not use the microSD (storage {}): flash it first".format(port, storage))
    if line.startswith(NO_CARD):
        raise SyncError("the board says: " + line[len("phpbm: "):].split(":", 1)[0])
    if line.startswith("--- stopped: no ") and line.rstrip(" -").endswith(STOPPED_NO_ENTRY):
        return True
    if line.startswith("--- stopped:"):
        raise SyncError("the board says: " + line.strip("- "))
    return False


def hello(link, timeout, restarts=3, code=True):
    """Resets the board and greets it until READY; a board stopped for a missing entry restarts, and its next boot is awaited."""
    link.reset()
    end = time.monotonic() + timeout
    next_hello = 0.0
    while time.monotonic() < end:
        now = time.monotonic()
        if now >= next_hello:
            link.try_send(HELLO)
            next_hello = now + 0.05
        line = link.line(0.05)
        if line is None:
            continue
        if line.startswith("PHPBM-SYNC READY "):
            link.flush_input()
            return int(line.split()[2])
        if boot_line(line, link.name, code):
            if restarts == 0:
                raise SyncError("the board says: " + line.strip("- "))
            restarts -= 1
            end = time.monotonic() + timeout
    raise SyncError("the board did not answer the sync: is it running a microSD firmware? (flash it first)")


def speed_up(link):
    """Moves the link to the fastest rate both ends manage; BASE_BAUD when none (USB console or an older firmware)."""
    for baud in BAUDS:
        link.send("BAUD {}\n".format(baud).encode())
        answer = link.reply()
        if answer in ("ERR not needed", "ERR unknown command"):
            break
        if answer != "OK":
            continue
        switched = time.monotonic()
        try:
            link.set_baud(baud)
            if link.ping(0.8):
                return baud
        except (ValueError, OSError):
            pass
        time.sleep(max(0.0, switched + BAUD_FALLBACK - time.monotonic()))
        try:
            link.set_baud(BASE_BAUD)
        except (ValueError, OSError):
            return BASE_BAUD
        link.flush_input()
        link.send(b"\n")
    return BASE_BAUD


def list_remote(link):
    link.send(b"\nLIST\n")
    remote = {}
    while True:
        line = link.line(IDLE)
        if line is None:
            raise SyncError("the board stopped answering while listing the card")
        if line.startswith("ERR "):
            raise SyncError("the board could not list the card: " + line[4:])
        if line == "END":
            return remote
        entry = parse_entry(line)
        if entry is not None:
            remote[entry[0]] = entry[1]


def declare_roots(link, roots):
    """Tells the firmware the folders it may change: nothing outside them is listed, written or deleted."""
    for root in roots:
        link.send("ROOT {}\n".format(root).encode("utf-8"))
        answer = link.reply()
        if answer != "OK":
            raise SyncError("the board refused the folder {}: {}".format(root, answer[4:] if answer.startswith("ERR ") else answer))


def put(link, file, path, sha):
    with open(file, "rb") as f:
        data = f.read()
    link.send("PUT {} {} {}\n".format(len(data), sha, path).encode("utf-8"))
    answer = link.reply()
    if answer != "OK":
        return answer
    for chunk in chunks(data):
        link.send(chunk)
        answer = link.reply()
        if answer != "OK":
            return answer
    answer = link.reply()
    return None if answer == "DONE " + path else answer


def sync(link, src, out, exclude=(), assets=None):
    """src: the sources (None when the code is in flash); assets: see collect()."""
    local, roots = collect(src, exclude, assets)
    free = hello(link, 30.0, code=src is not None)
    baud = speed_up(link)
    if baud != BASE_BAUD:
        out("serial link at {} baud".format(baud))
    declare_roots(link, roots)
    remote = list_remote(link)
    deletes, puts = plan(local, remote, roots)
    need = sum(local[p][1] for p in puts)
    if need + MARGIN > free:
        raise SyncError("not enough space on the card: {} KB to send, {} KB free".format(need // 1024, free // 1024))
    errors = 0
    for path in deletes:
        link.send("DEL {}\n".format(path).encode("utf-8"))
        answer = link.reply()
        if answer == "OK":
            out("del {}".format(path))
        else:
            errors += 1
            out("error: del {}: {}".format(path, answer[4:] if answer.startswith("ERR ") else answer))
    sent = files = 0
    started = time.monotonic()
    for path in puts:
        sha, size, file = local[path]
        problem = put(link, file, path, sha)
        if problem is None:
            sent += size
            files += 1
            out("put {} ({} B)".format(path, size))
        else:
            errors += 1
            out("error: put {}: {}".format(path, problem[4:] if problem.startswith("ERR ") else problem))
    elapsed = time.monotonic() - started
    link.send(b"BYE\n")
    link.reply()
    out(summary(files, sent, elapsed, len(deletes), len(local) - len(puts), free))
    return errors


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", required=True)
    parser.add_argument("--exclude", action="append", default=[], help="a file inside src to leave out")
    parser.add_argument("--assets", help='a JSON file: {"roots": [card folder, ...], "files": {card path: local file}}')
    parser.add_argument("src", nargs="?", help="the sources, copied to /sdcard/app")
    args = parser.parse_args(argv)
    if args.src is None and args.assets is None:
        print("error: nothing to sync: give the source directory or --assets", file=sys.stderr)
        return 1
    if args.src is not None and not os.path.isdir(args.src):
        print("error: no source directory {}".format(args.src), file=sys.stderr)
        return 1
    link = None
    try:
        assets = None
        if args.assets is not None:
            with open(args.assets, encoding="utf-8") as f:
                assets = json.load(f)
        link = Link(args.port)
        errors = sync(link, args.src, lambda line: print(line, flush=True), args.exclude, assets)
    except (SyncError, OSError, UnicodeError, ValueError) as e:
        print("error: {}".format(e), file=sys.stderr)
        return 1
    except Exception as e:  # noqa: BLE001 - one clear line for phpbm instead of a traceback
        print("error: unexpected failure ({}: {})".format(type(e).__name__, e), file=sys.stderr)
        return 1
    finally:
        if link is not None:
            link.close()
    if errors:
        print("error: {} file(s) could not be synced".format(errors), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())

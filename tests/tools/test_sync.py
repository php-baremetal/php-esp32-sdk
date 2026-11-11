"""Pure-logic tests of driver/tools/sync.py: python3 -m unittest discover -s tests/tools"""

import hashlib
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "..", "driver", "tools"))

import sync  # noqa: E402

A = "a" * 64
B = "b" * 64


class SyncLogicTest(unittest.TestCase):
    def test_valid_path(self):
        for ok in ("index.php", "public/style.css", "a/b/.htaccess", "x..y", "dir/with space.txt", "a/b.c"):
            self.assertTrue(sync.valid_path(ok), ok)
        for bad in ("", "/etc/passwd", "../x", "a/../b", "a/..", "./a", "a//b", "a/", "a\\b", "c:x", "a\nb", "x" * 256,
                    "a.", "a /b", "dir/x ", 'q"x', "a*b", "a<b", "a>b", "a?b", "a|b", "caf\u00e9.php", "dir./x"):
            self.assertFalse(sync.valid_path(bad), repr(bad))

    def test_parse_entry(self):
        self.assertEqual(("public/a b.php", (A, 12)), sync.parse_entry("F {} 12 public/a b.php".format(A)))
        for other in ("I (123) phpbm: hello", "F short 1 x", "F {} -1 x".format(A), "F {} 1".format(A), "END"):
            self.assertIsNone(sync.parse_entry(other), other)

    def test_plan(self):
        local = {"app/same.php": (A, 1, "/l/same.php"), "app/changed.php": (B, 2, "/l/changed.php"), "app/new/x.php": (A, 3, "/l/x.php")}
        remote = {"app/same.php": (A, 1), "app/changed.php": (A, 2), "app/old.php": (A, 4), "../bad": (A, 1), "app/x.php.part": (A, 1)}
        self.assertEqual((["app/old.php", "app/x.php.part"], ["app/changed.php", "app/new/x.php"]), sync.plan(local, remote, ["app"]))
        self.assertEqual(([], []), sync.plan(local, {p: v[:2] for p, v in local.items()}, ["app"]))

    def test_plan_deletes_only_inside_the_managed_roots(self):
        remote = {"app/old.php": (A, 1), "assets/photos/gone.sli": (A, 1), "assets/keep.txt": (A, 1),
                  "data/log.txt": (A, 1), "apps/x.php": (A, 1), "assets/photos2/y.sli": (A, 1)}
        self.assertEqual((["app/old.php", "assets/photos/gone.sli"], []), sync.plan({}, remote, ["app", "assets/photos"]))
        self.assertEqual((["assets/photos/gone.sli"], []), sync.plan({}, remote, ["assets/photos"]))

    def test_in_roots(self):
        self.assertTrue(sync.in_roots("app/index.php", ["app"]))
        self.assertTrue(sync.in_roots("assets/photos/a/b.sli", ["app", "assets/photos"]))
        for outside in ("app", "apps/x", "assets/photos", "assets/photosx/y", "other/app/x"):
            self.assertFalse(sync.in_roots(outside, ["app", "assets/photos"]), outside)

    def test_collect_maps_sources_under_app_and_assets_by_card_path(self):
        with tempfile.TemporaryDirectory() as src, tempfile.TemporaryDirectory() as cache:
            os.makedirs(os.path.join(src, "lib"))
            for name, data in (("index.php", b"<?php\n"), ("lib/a.php", b"<?php //a\n"), (".env", b"X=1\n")):
                with open(os.path.join(src, *name.split("/")), "wb") as f:
                    f.write(data)
            beans = os.path.join(cache, "beans.sli")
            with open(beans, "wb") as f:
                f.write(b"SLI1")
            local, roots = sync.collect(src, (), {"roots": ["assets/photos"], "files": {"assets/photos/sub/beans.sli": beans}})
        self.assertEqual(["app", "assets/photos"], roots)
        self.assertEqual(["app/index.php", "app/lib/a.php", "assets/photos/sub/beans.sli"], sorted(local))
        self.assertEqual((hashlib.sha256(b"SLI1").hexdigest(), 4, beans), local["assets/photos/sub/beans.sli"])
        self.assertEqual(os.path.join(src, "lib", "a.php"), local["app/lib/a.php"][2])

    def test_collect_without_sources_manages_only_the_asset_roots(self):
        with tempfile.TemporaryDirectory() as cache:
            local, roots = sync.collect(None, (), {"roots": ["assets/photos", "media"], "files": {}})
        self.assertEqual(({}, ["assets/photos", "media"]), (local, roots))

    def test_collect_takes_an_empty_files_list_or_object(self):
        for files in ([], {}, None):
            self.assertEqual(({}, ["assets/photos"]), sync.collect(None, (), {"roots": ["assets/photos"], "files": files}))

    def test_collect_refuses_a_malformed_assets_file(self):
        for assets in ([], "x", {"roots": "a", "files": {}}, {"roots": [1], "files": {}}, {"roots": ["a"], "files": [["a/x", "/y"]]},
                       {"roots": ["a"], "files": {"a/x": 3}}):
            with self.assertRaisesRegex(sync.SyncError, "malformed assets file", msg=repr(assets)):
                sync.collect(None, (), assets)

    def test_collect_compares_the_sources_folder_case_insensitively(self):
        with tempfile.TemporaryDirectory() as src:
            with self.assertRaisesRegex(sync.SyncError, "inside the sources' folder"):
                sync.collect(src, (), {"roots": ["App/photos"], "files": {}})
            with self.assertRaisesRegex(sync.SyncError, "overlap"):
                sync.collect(None, (), {"roots": ["Media", "media/x"], "files": {}})

    def test_main_reports_an_unexpected_failure_in_one_line(self):
        import io
        import contextlib

        link = sync.Link

        def broken(port):
            raise RuntimeError("boom")

        sync.Link = broken
        err = io.StringIO()
        try:
            with tempfile.TemporaryDirectory() as src, contextlib.redirect_stderr(err):
                self.assertEqual(1, sync.main(["--port", "/dev/x", src]))
        finally:
            sync.Link = link
        self.assertEqual("error: unexpected failure (RuntimeError: boom)\n", err.getvalue())

    def test_collect_refuses_bad_roots_and_asset_paths(self):
        with tempfile.TemporaryDirectory() as src:
            beans = os.path.join(src, "beans.sli")
            open(beans, "w").close()
            cases = [
                ({"roots": ["app/img"], "files": {}}, src, "inside the sources' folder"),
                ({"roots": ["app"], "files": {}}, src, "inside the sources' folder"),
                ({"roots": ["a", "a/b"], "files": {}}, None, "overlap"),
                ({"roots": ["a", "a"], "files": {}}, None, "overlap"),
                ({"roots": ["../x"], "files": {}}, None, "unsupported folder name"),
                ({"roots": ["a"], "files": {"b/beans.sli": beans}}, None, "outside the managed folders"),
                ({"roots": ["a"], "files": {"a/x.part": beans}}, None, "unsupported file name"),
                ({"roots": ["a"], "files": {"a/X.sli": beans, "a/x.sli": beans}}, None, "case-insensitive"),
            ]
            for assets, code, why in cases:
                with self.assertRaisesRegex(sync.SyncError, why, msg=repr(assets)):
                    sync.collect(code, (), assets)
            with self.assertRaisesRegex(sync.SyncError, "no asset file"):
                sync.collect(None, (), {"roots": ["a"], "files": {"a/missing.sli": os.path.join(src, "missing.sli")}})

    def test_scan_skips_env_and_hashes(self):
        with tempfile.TemporaryDirectory() as src:
            os.makedirs(os.path.join(src, "public", "img"))
            data = bytes(range(256)) * 800
            with open(os.path.join(src, "public", "img", "big.bin"), "wb") as f:
                f.write(data)
            with open(os.path.join(src, ".env"), "w") as f:
                f.write("SECRET=1\n")
            with open(os.path.join(src, "index.php"), "w") as f:
                f.write("<?php\n")
            files = sync.scan(src)
        self.assertEqual(["index.php", "public/img/big.bin"], sorted(files))
        self.assertEqual((hashlib.sha256(data).hexdigest(), len(data)), files["public/img/big.bin"])

    def test_scan_refuses_part_and_old_files(self):
        for name in ("x.part", "x.php.old", "X.PART", "y.Old"):
            with tempfile.TemporaryDirectory() as src:
                open(os.path.join(src, name), "w").close()
                with self.assertRaises(sync.SyncError):
                    sync.scan(src)

    def test_scan_refuses_invalid_and_colliding_names(self):
        with tempfile.TemporaryDirectory() as src:
            open(os.path.join(src, "bad.php."), "w").close()
            with self.assertRaises(sync.SyncError):
                sync.scan(src)
        with tempfile.TemporaryDirectory() as src:
            os.makedirs(os.path.join(src, "d"))
            open(os.path.join(src, "d", "A.php"), "w").close()
            open(os.path.join(src, "d", "a.php"), "w").close()
            with self.assertRaisesRegex(sync.SyncError, "case-insensitive"):
                sync.scan(src)

    def test_scan_excludes_the_given_files(self):
        with tempfile.TemporaryDirectory() as src:
            os.makedirs(os.path.join(src, "conf"))
            open(os.path.join(src, "conf", "prod.env"), "w").close()
            open(os.path.join(src, "index.php"), "w").close()
            self.assertEqual(["index.php"], sorted(sync.scan(src, [os.path.join(src, "conf", "prod.env")])))

    def test_chunks(self):
        data = b"x" * (sync.CHUNK * 2 + 5)
        parts = list(sync.chunks(data))
        self.assertEqual([b"4096\n", b"4096\n", b"5\n"], [p[:p.index(b"\n") + 1] for p in parts])
        self.assertEqual(data, b"".join(p[p.index(b"\n") + 1:] for p in parts))
        self.assertEqual([], list(sync.chunks(b"")))

    def test_summary(self):
        self.assertEqual("up to date: 3 unchanged, 1 deleted; 14 MB free on the card", sync.summary(0, 0, 0.0, 1, 3, 14 * 1024 * 1024 + 5))
        self.assertEqual("sent 2 file(s), 200.0 KB in 2.0 s (100 KB/s); 1 unchanged, 0 deleted; 1 MB free on the card",
                         sync.summary(2, 204800, 2.0, 0, 1, 1024 * 1024))


class FakeLink:
    """Answers like a firmware: lines come from a script keyed by what the host sent."""

    def __init__(self, lines=(), answers=None, pong_at=()):
        self.name = "/dev/fake"
        self.lines = list(lines)
        self.answers = dict(answers or {})
        self.pong_at = set(pong_at)
        self.baud = sync.BASE_BAUD
        self.sent = []
        self.resets = 0

    def reset(self):
        self.resets += 1

    def try_send(self, data):
        pass

    def line(self, timeout):
        return self.lines.pop(0) if self.lines else None

    def flush_input(self):
        pass

    def send(self, data, timeout=10.0):
        self.sent.append(data)

    def reply(self, timeout=15.0):
        return self.answers[self.sent[-1]]

    def set_baud(self, baud):
        self.baud = baud

    def ping(self, timeout):
        return self.baud in self.pong_at


class LinkLogicTest(unittest.TestCase):
    def setUp(self):
        self.sleep = sync.time.sleep
        sync.time.sleep = lambda s: None

    def tearDown(self):
        sync.time.sleep = self.sleep

    def test_boot_lines(self):
        self.assertFalse(sync.boot_line("phpbm: storage microsd", "/dev/x"))
        self.assertFalse(sync.boot_line("I (12) boot: x", "/dev/x"))
        with self.assertRaisesRegex(sync.SyncError, "the firmware on /dev/x does not use the microSD"):
            sync.boot_line("phpbm: storage embedded", "/dev/x")
        self.assertTrue(sync.boot_line("--- stopped: no public/index.php on the microSD card (run phpbm sync) ---", "/dev/x"))
        with self.assertRaisesRegex(sync.SyncError, "no microSD card"):
            sync.boot_line("--- stopped: no microSD card ---", "/dev/x")

    def test_boot_lines_of_a_firmware_with_microsd_assets(self):
        line = "phpbm: storage embedded, assets on microsd"
        self.assertFalse(sync.boot_line(line, "/dev/x", code=False))
        self.assertFalse(sync.boot_line("phpbm: storage microsd", "/dev/x", code=False))
        with self.assertRaisesRegex(sync.SyncError, r"keeps the sources in flash \(storage embedded\): flash it first"):
            sync.boot_line(line, "/dev/x")
        with self.assertRaisesRegex(sync.SyncError, "the firmware on /dev/x does not use the microSD"):
            sync.boot_line("phpbm: storage embedded", "/dev/x", code=False)
        with self.assertRaisesRegex(sync.SyncError, "the board says: microSD not mounted \\(ESP_ERR_TIMEOUT\\)"):
            sync.boot_line("phpbm: microSD not mounted (ESP_ERR_TIMEOUT): its assets are not available", "/dev/x", code=False)

    def test_hello_waits_through_a_missing_entry(self):
        stopped = "--- stopped: no index.php on the microSD card (run phpbm sync) ---"
        link = FakeLink(["phpbm: storage microsd", stopped, "phpbm: restarting in 3 s", "PHPBM-SYNC READY 1234"])
        self.assertEqual(b"PHPBM-SYNC 2\n", sync.HELLO, "paths are relative to the card since version 2")
        self.assertEqual(1234, sync.hello(link, 5.0))
        self.assertEqual(1, link.resets)
        with self.assertRaisesRegex(sync.SyncError, "no index.php"):
            sync.hello(FakeLink([stopped] * 3), 5.0, restarts=2)
        with self.assertRaisesRegex(sync.SyncError, "does not use the microSD"):
            sync.hello(FakeLink(["phpbm: storage embedded", "PHPBM-SYNC READY 1"]), 5.0)

    def test_speed_up_takes_the_first_rate_that_answers(self):
        answers = {b"BAUD 921600\n": "OK", b"BAUD 460800\n": "OK"}
        link = FakeLink(answers=answers, pong_at={921600})
        self.assertEqual(921600, sync.speed_up(link))
        link = FakeLink(answers=answers, pong_at={460800})
        self.assertEqual(460800, sync.speed_up(link))
        self.assertEqual(460800, link.baud)
        link = FakeLink(answers=answers)
        self.assertEqual(sync.BASE_BAUD, sync.speed_up(link))
        self.assertEqual(sync.BASE_BAUD, link.baud)

    def test_speed_up_survives_a_port_that_refuses_the_rate(self):
        class Refusing(FakeLink):
            def set_baud(self, baud):
                if baud == 921600:
                    raise ValueError("Invalid baud rate")
                super().set_baud(baud)

        answers = {b"BAUD 921600\n": "OK", b"BAUD 460800\n": "OK"}
        link = Refusing(answers=answers, pong_at={460800})
        self.assertEqual(460800, sync.speed_up(link))

        class Dead(FakeLink):
            def set_baud(self, baud):
                if baud != sync.BASE_BAUD:
                    raise OSError("device disappeared")
                super().set_baud(baud)

        link = Dead(answers=answers)
        self.assertEqual(sync.BASE_BAUD, sync.speed_up(link))
        self.assertEqual(sync.BASE_BAUD, link.baud)

    def test_speed_up_stops_on_firmwares_that_cannot(self):
        for answer in ("ERR not needed", "ERR unknown command"):
            link = FakeLink(answers={b"BAUD 921600\n": answer})
            self.assertEqual(sync.BASE_BAUD, sync.speed_up(link))
            self.assertEqual([b"BAUD 921600\n"], link.sent)


class FakeBoard:
    """A firmware with its card as a dict: card path -> bytes. Follows the protocol of host/components/phpbm/sync.c."""

    def __init__(self, card, storage="microsd"):
        self.name = "/dev/fake"
        self.card = dict(card)
        self.storage = storage
        self.queue = []
        self.roots = []
        self.commands = []
        self.put = None

    def reset(self):
        self.queue.append("phpbm: storage " + self.storage)

    def try_send(self, data):
        if data == sync.HELLO:
            self.queue.append("PHPBM-SYNC READY 1000000")

    def flush_input(self):
        pass

    def set_baud(self, baud):
        pass

    def ping(self, timeout):
        return False

    def line(self, timeout):
        return self.queue.pop(0) if self.queue else None

    def reply(self, timeout=15.0):
        while self.queue:
            line = self.queue.pop(0)
            if line in ("OK", "BYE") or line.startswith(("ERR ", "DONE ")):
                return line
        raise sync.SyncError("the board stopped answering")

    def inside(self, path):
        return any(path.startswith(root + "/") for root in self.roots)

    def send(self, data, timeout=10.0):
        if self.put is not None:
            head, body = data.split(b"\n", 1)
            assert int(head) == len(body)
            self.put[2].extend(body)
            self.queue.append("OK")
            if len(self.put[2]) == self.put[1]:
                self.card[self.put[0]] = bytes(self.put[2])
                self.queue.append("DONE " + self.put[0])
                self.put = None
            return
        for line in data.decode().split("\n"):
            if line == "":
                continue
            self.commands.append(line)
            word, _, arg = line.partition(" ")
            if word == "BAUD":
                self.queue.append("ERR not needed")
            elif word == "ROOT":
                self.roots.append(arg)
                self.queue.append("OK")
            elif word == "LIST":
                for path in sorted(p for p in self.card if self.inside(p)):
                    self.queue.append("F {} {} {}".format(hashlib.sha256(self.card[path]).hexdigest(), len(self.card[path]), path))
                self.queue.append("END")
            elif word == "DEL":
                if not self.inside(arg):
                    self.queue.append("ERR outside the managed folders")
                else:
                    del self.card[arg]
                    self.queue.append("OK")
            elif word == "PUT":
                size, _, path = arg.split(" ", 2)
                if not self.inside(path):
                    self.queue.append("ERR outside the managed folders")
                    continue
                self.queue.append("OK")
                self.put = (path, int(size), bytearray())
                if int(size) == 0:
                    self.card[path] = b""
                    self.queue.append("DONE " + path)
                    self.put = None
            elif word == "BYE":
                self.queue.append("BYE")


class SessionTest(unittest.TestCase):
    def setUp(self):
        self.sleep = sync.time.sleep
        sync.time.sleep = lambda s: None

    def tearDown(self):
        sync.time.sleep = self.sleep

    def test_sources_and_assets_change_only_the_managed_folders(self):
        card = {"app/index.php": b"old", "app/old.php": b"x", "assets/photos/gone.sli": b"g",
                "assets/photos/same.sli": b"same", "assets/keep.txt": b"k", "data/log.txt": b"l", "ota/firmware.bin": b"f"}
        board = FakeBoard(card)
        lines = []
        with tempfile.TemporaryDirectory() as src, tempfile.TemporaryDirectory() as cache:
            with open(os.path.join(src, "index.php"), "wb") as f:
                f.write(b"<?php echo 1;\n")
            files = {}
            for name, data in (("beans.sli", b"B" * (sync.CHUNK + 10)), ("same.sli", b"same")):
                with open(os.path.join(cache, name), "wb") as f:
                    f.write(data)
                files["assets/photos/" + name] = os.path.join(cache, name)
            errors = sync.sync(board, src, lines.append, (), {"roots": ["assets/photos"], "files": files})
        self.assertEqual(0, errors)
        self.assertEqual(["ROOT app", "ROOT assets/photos"], [c for c in board.commands if c.startswith("ROOT ")])
        self.assertEqual({"app/index.php": b"<?php echo 1;\n", "assets/photos/beans.sli": b"B" * (sync.CHUNK + 10),
                          "assets/photos/same.sli": b"same", "assets/keep.txt": b"k", "data/log.txt": b"l", "ota/firmware.bin": b"f"}, board.card)
        self.assertEqual(["del app/old.php", "del assets/photos/gone.sli", "put app/index.php (14 B)", "put assets/photos/beans.sli (4106 B)"], lines[:4])
        self.assertTrue(lines[-1].startswith("sent 2 file(s), "))
        self.assertTrue(lines[-1].endswith("; 1 unchanged, 2 deleted; 0 MB free on the card"))

    def test_assets_only_with_the_code_in_flash(self):
        board = FakeBoard({"app/index.php": b"theirs", "assets/photos/a.sli": b"a"}, storage="embedded, assets on microsd")
        lines = []
        with tempfile.TemporaryDirectory() as cache:
            errors = sync.sync(board, None, lines.append, (), {"roots": ["assets/photos"], "files": {}})
        self.assertEqual(0, errors)
        self.assertEqual(["ROOT assets/photos"], [c for c in board.commands if c.startswith("ROOT ")])
        self.assertEqual({"app/index.php": b"theirs"}, board.card, "a folder that is not managed stays as it is")

    def test_code_needs_a_microsd_firmware(self):
        board = FakeBoard({}, storage="embedded, assets on microsd")
        with tempfile.TemporaryDirectory() as src:
            open(os.path.join(src, "index.php"), "w").close()
            with self.assertRaisesRegex(sync.SyncError, "keeps the sources in flash"):
                sync.sync(board, src, lambda line: None)

    def test_a_refused_root_stops_the_session(self):
        class Refusing(FakeBoard):
            def send(self, data, timeout=10.0):
                if data.startswith(b"ROOT "):
                    self.queue.append("ERR too many folders")
                    return
                super().send(data, timeout)

        with tempfile.TemporaryDirectory() as src:
            open(os.path.join(src, "index.php"), "w").close()
            with self.assertRaisesRegex(sync.SyncError, "the board refused the folder app: too many folders"):
                sync.sync(Refusing({}), src, lambda line: None)


if __name__ == "__main__":
    unittest.main()

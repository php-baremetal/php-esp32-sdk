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
        local = {"same.php": (A, 1), "changed.php": (B, 2), "new/x.php": (A, 3)}
        remote = {"same.php": (A, 1), "changed.php": (A, 2), "old.php": (A, 4), "../bad": (A, 1), "x.php.part": (A, 1)}
        self.assertEqual((["old.php", "x.php.part"], ["changed.php", "new/x.php"]), sync.plan(local, remote))
        self.assertEqual(([], []), sync.plan(local, dict(local)))

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

    def test_hello_waits_through_a_missing_entry(self):
        stopped = "--- stopped: no index.php on the microSD card (run phpbm sync) ---"
        link = FakeLink(["phpbm: storage microsd", stopped, "phpbm: restarting in 3 s", "PHPBM-SYNC READY 1234"])
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


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
import urllib.parse
import urllib.request
import urllib.error
import time
from datetime import datetime

TARGET_SERVER_ADDRESS = "http://localhost/data"
LIST_FILE = "list.txt"
ERROR_LOG = "error_log.txt"


def urlencode_path(path: str) -> str:
    return urllib.parse.quote(path, safe="/")


def check_url(path: str) -> bool:
    file_encoded = urlencode_path(path)
    url = f"{TARGET_SERVER_ADDRESS.rstrip('/')}/{file_encoded.lstrip('/')}"
    req = urllib.request.Request(url, method="HEAD")

    try:
        with urllib.request.urlopen(req) as resp:
            code = resp.status
            ctype = resp.headers.get("Content-Type", "")
    except urllib.error.HTTPError as e:
        code = e.code
        ctype = e.headers.get("Content-Type", "") if e.headers else ""
    except Exception as e:
        msg = f"FAIL: exception {e}, url: {url}\n"
        print(msg, end="")
        with open(ERROR_LOG, "a", encoding="utf-8") as f:
            f.write(msg)
        return False

    if code == 200:
        print(f"OK: status 200 and Content-Type present ({ctype})")
        return True
    else:
        msg = f"FAIL: HTTP code {code}, url: {url}\n"
        print(msg, end="")
        with open(ERROR_LOG, "a", encoding="utf-8") as f:
            f.write(msg)
        return False


def main() -> None:
    try:
        with open(LIST_FILE, encoding="utf-8") as f:
            lines = [line.rstrip("\n") for line in f if line.strip()]
    except FileNotFoundError:
        print(f"{LIST_FILE} not found")
        return

    start_dt = datetime.now()
    start_time = time.perf_counter()

    print(f"Start time: {start_dt:%Y-%m-%d %H:%M:%S}")
    print(f"Checking for {len(lines)} files")

    ok_count = 0
    fail_count = 0

    for line in lines:
        print(line)
        if check_url(line):
            ok_count += 1
        else:
            fail_count += 1

    elapsed = time.perf_counter() - start_time

    total = ok_count + fail_count
    print("\n=== Final result ===")
    print(f"Total: {total} | OK: {ok_count} | FAIL: {fail_count}")
    print(f"Elapsed time: {elapsed:.2f} seconds")

if __name__ == "__main__":
    main()
# Apache




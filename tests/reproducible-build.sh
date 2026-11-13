#!/usr/bin/env bash
# A112: the release build is reproducible – two builds of the same commit at different times and in
# different temporary directories must give a byte-identical ZIP, and the written checksum must match.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
ref="${WPCAP_BUILD_REF:-HEAD}"
fail=0
WPCAP_BUILD_REF="$ref" bash "$root/build/release.sh" >/dev/null 2>&1
cp "$root/build/dist/pass-captain.zip" "$tmp/first.zip"
sleep 1
WPCAP_BUILD_REF="$ref" bash "$root/build/release.sh" >/dev/null 2>&1
if cmp -s "$root/build/dist/pass-captain.zip" "$tmp/first.zip"; then echo "ok   two builds of the same commit are byte-identical"; else echo "FAIL builds differ"; fail=1; fi
if (cd "$root/build/dist" && sha256sum -c --quiet pass-captain.zip.sha256); then echo "ok   pass-captain.zip.sha256 matches the ZIP"; else echo "FAIL checksum file does not match"; fail=1; fi
commit="$(git -C "$root" rev-parse "${ref}^{commit}")"
if grep -qx "commit=${commit}" "$root/build/dist/build-info.txt"; then echo "ok   build-info.txt names the built commit"; else echo "FAIL build-info.txt commit"; fail=1; fi
epoch="$(git -C "$root" log -1 --format=%ct "$commit")"
newest="$(unzip -Z -T "$root/build/dist/pass-captain.zip" | awk 'NF>=8 {print $7}' | sort -u | tr '\n' ' ')"
expected="$(TZ=UTC date -d "@${epoch}" +%Y%m%d.%H%M%S)"
case " $newest" in *" ${expected:0:13}"*) echo "ok   file times in the ZIP are the commit time";; *) echo "FAIL file times: $newest (expected $expected)"; fail=1;; esac
exit $fail

#!/usr/bin/env bash
# Extended integration run (CI-able, e.g. nightly): every statement of the fault matrices, 5,000-order
# privacy volume test, 10x money property cases and 300 random state machine sequences.
# Same database requirements as run.php (WPCAP_TEST_DB_HOST/_USER/_PASS/_NAME); takes a few minutes.
set -euo pipefail
cd "$(dirname "$0")/../.."
WPCAP_TEST_SLOW=1 exec php tests/integration/run.php "$@"

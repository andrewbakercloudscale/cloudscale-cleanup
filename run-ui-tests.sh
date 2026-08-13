#!/usr/bin/env bash
# Thin wrapper -> canonical runner in shared-test-lib/ (Test Account Manager auth).
#
# This repo was the only one of the five without this file, so its suite could only be run by
# knowing to invoke ../shared-test-lib/run-ui-tests.sh by hand — which is how its one spec came to
# be years out of date without anyone noticing.
exec bash "$(cd "$(dirname "${BASH_SOURCE[0]}")/../shared-test-lib" && pwd)/run-ui-tests.sh" "$@"

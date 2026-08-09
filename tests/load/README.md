# Authorized load testing

Run only against an explicitly authorized, isolated environment containing synthetic data. Start with a low `RATE`; establish baseline, then high, peak, stress, spike, soak and recovery scenarios under an approved rules-of-engagement and stop conditions.

Example: `k6 run -e BASE_URL=https://authorized-test.example -e RATE=10 -e DURATION=2m tests/load/invoice-submission.k6.js`

The script’s default workload is deliberately small. National-scale targets require distributed generators, realistic traffic mixes and data cardinality, independent monitoring and reconciliation of every accepted transaction. The test owner must watch edge/WAF, application, queue, database, security and recovery signals and record the exact build and environment.

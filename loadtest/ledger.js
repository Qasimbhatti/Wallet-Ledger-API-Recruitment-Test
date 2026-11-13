// k6 load test for the Wallet Ledger API.
//
//   docker compose --profile loadtest run --rm k6
//
// Phase 1 "mixed"  (0s → DURATION):       VUS clients, 40% credit / 20% debit / 40% balance,
//                                          random users from the seeded population.
// Phase 2 "hot"    (after phase 1):       70% of VUS hammer ONE user with credits/debits,
//                                          while 30% ("bystanders") keep doing mixed traffic
//                                          on other users. Bystander latency shows isolation.
//
// Reports p50/p99 per endpoint and phase, and fails if any request returns a
// status other than the expected 200/409 (404 only for brand-new users' debits).

import http from 'k6/http';
import { check } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const TOKEN = __ENV.API_TOKEN || 'recruitment-test-token';
const SEED_USERS = parseInt(__ENV.SEED_USERS || '100000', 10);
const DURATION = __ENV.DURATION || '60s';
const VUS = parseInt(__ENV.VUS || '100', 10);
const HOT_USER = __ENV.HOT_USER || 'user_000000'; // a 150k-row whale from the seed

const durationSeconds = parseInt(DURATION, 10);
const HOT_VUS = Math.max(1, Math.round(VUS * 0.7));
const BYSTANDER_VUS = Math.max(1, VUS - HOT_VUS);

const HEADERS = { headers: { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json' } };

const trends = {};
for (const phase of ['mixed', 'hot', 'bystander']) {
  for (const endpoint of ['credit', 'debit', 'balance']) {
    trends[`${phase}_${endpoint}`] = new Trend(`${phase}_${endpoint}`, true);
  }
}
const unexpected = new Counter('unexpected_responses');

export const options = {
  scenarios: {
    mixed: { executor: 'constant-vus', exec: 'mixed', vus: VUS, duration: DURATION },
    hot: { executor: 'constant-vus', exec: 'hot', vus: HOT_VUS, duration: DURATION, startTime: `${durationSeconds + 5}s` },
    bystander: { executor: 'constant-vus', exec: 'bystander', vus: BYSTANDER_VUS, duration: DURATION, startTime: `${durationSeconds + 5}s` },
  },
  thresholds: { unexpected_responses: ['count==0'] },
  summaryTrendStats: ['med', 'p(95)', 'p(99)', 'max', 'count'],
  discardResponseBodies: false,
};

// 200 = success, 409 = expected for debits racing a low balance.
http.setResponseCallback(http.expectedStatuses(200, 409));

function seededUser() {
  const n = Math.floor(Math.random() * SEED_USERS);
  return `user_${String(n).padStart(Math.max(6, String(SEED_USERS - 1).length), '0')}`;
}

function record(phase, endpoint, res, allowed) {
  trends[`${phase}_${endpoint}`].add(res.timings.duration);
  const ok = allowed.includes(res.status);
  if (!ok) {
    unexpected.add(1);
    console.error(`${endpoint} -> ${res.status}: ${res.body}`);
  }
  check(res, { [`${endpoint} status expected`]: () => ok });
}

function credit(phase, user) {
  const amount = 1 + Math.floor(Math.random() * 10000);
  const res = http.post(`${BASE_URL}/credit`, JSON.stringify({ user_id: user, amount }), HEADERS);
  record(phase, 'credit', res, [200]);
}

function debit(phase, user) {
  const amount = 1 + Math.floor(Math.random() * 10000);
  const res = http.post(`${BASE_URL}/debit`, JSON.stringify({ user_id: user, amount }), HEADERS);
  record(phase, 'debit', res, [200, 409]);
}

function balance(phase, user) {
  const res = http.get(`${BASE_URL}/balance/${user}`, HEADERS);
  record(phase, 'balance', res, [200]);
}

function mixedOp(phase) {
  const r = Math.random();
  const user = seededUser();
  if (r < 0.4) credit(phase, user);
  else if (r < 0.6) debit(phase, user);
  else balance(phase, user);
}

export function mixed() {
  mixedOp('mixed');
}

export function hot() {
  const r = Math.random();
  if (r < 0.45) credit('hot', HOT_USER);
  else if (r < 0.9) debit('hot', HOT_USER);
  else balance('hot', HOT_USER);
}

export function bystander() {
  mixedOp('bystander');
}

export function handleSummary(data) {
  const rows = [];
  for (const name of Object.keys(trends)) {
    const m = data.metrics[name];
    if (!m || !m.values || !m.values.count) continue;
    const v = m.values;
    rows.push(`| ${name.padEnd(17)} | ${String(v.count).padStart(8)} | ${v.med.toFixed(2).padStart(8)} | ${v['p(95)'].toFixed(2).padStart(8)} | ${v['p(99)'].toFixed(2).padStart(8)} | ${v.max.toFixed(2).padStart(8)} |`);
  }
  const reqs = data.metrics.http_reqs ? data.metrics.http_reqs.values : { count: 0, rate: 0 };
  const bad = data.metrics.unexpected_responses ? data.metrics.unexpected_responses.values.count : 0;

  const table = [
    `VUs: ${VUS} (hot phase: ${HOT_VUS} on ${HOT_USER} + ${BYSTANDER_VUS} bystanders), duration per phase: ${DURATION}`,
    `Total requests: ${reqs.count} (${reqs.rate.toFixed(0)} req/s), unexpected responses: ${bad}`,
    '',
    '| phase_endpoint    | requests |  p50 ms  |  p95 ms  |  p99 ms  |  max ms  |',
    '|-------------------|---------:|---------:|---------:|---------:|---------:|',
    ...rows,
    '',
  ].join('\n');

  return {
    stdout: `\n${table}\n`,
    '/loadtest/results/summary.md': table,
    '/loadtest/results/summary.json': JSON.stringify(data, null, 2),
  };
}

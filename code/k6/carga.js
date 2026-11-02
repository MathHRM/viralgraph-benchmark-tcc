import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    carga: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RPS || 500),
      timeUnit: '1s',
      duration: __ENV.DURATION || '30s',
      preAllocatedVUs: Number(__ENV.VUS || 100),
      maxVUs: Number(__ENV.MAX_VUS || 500),
    },
  },
  thresholds: {
    http_req_failed: ['rate==0'],
    checks: ['rate==1'],
    dropped_iterations: ['count==0'],
  },
};

export default function () {
  const response = http.get(__ENV.URL || 'http://127.0.0.1:8000/api/health', {
    redirects: 0,
    timeout: '10s',
    headers: { 'X-Tcc-Load-Test': 'true' },
  });
  check(response, { 'status 200': (r) => r.status === 200 });
}

import http from 'k6/http';
import { check, sleep } from 'k6';
import encoding from 'k6/encoding';
import { Counter } from 'k6/metrics';
import exec from 'k6/execution';

const suite = (__ENV.STAGE9_SUITE || 'core').toLowerCase();
const baseUrl = (__ENV.STAGE9_BASE_URL || 'http://proxy:8080').replace(/\/$/, '');
const state = JSON.parse(encoding.b64decode(__ENV.STAGE9_STATE_BASE64 || '', 'std', 's'));
const users = state.users || [];
const expectedInstances = (__ENV.STAGE9_EXPECTED_INSTANCES || (suite === 'scale' ? 'app-1,app-2,app-3' : 'app-1,app-2'))
    .split(',')
    .map((value) => value.trim())
    .filter(Boolean);
const instanceHits = new Counter('stage9_instance_hits');

if (!state.article_id || users.length < 2 || !state.password || !state.session_name) {
    throw new Error('Nieprawidłowy stan danych testowych ETAPU 9.');
}

const commonThresholds = {
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    http_req_duration: [`p(95)<${Number(__ENV.STAGE9_P95_MS || 2500)}`],
};
for (const instance of expectedInstances) {
    commonThresholds[`stage9_instance_hits{instance:${instance}}`] = ['count>0'];
}

const coreScenarios = {
    article_reads: {
        executor: 'constant-vus',
        exec: 'articleReads',
        vus: Number(__ENV.STAGE9_READ_VUS || 4),
        duration: __ENV.STAGE9_READ_DURATION || '8s',
        gracefulStop: '3s',
    },
    login: {
        executor: 'per-vu-iterations',
        exec: 'loginCheck',
        vus: users.length,
        iterations: 1,
        startTime: '9s',
        maxDuration: '20s',
    },
    account: {
        executor: 'per-vu-iterations',
        exec: 'accountCheck',
        vus: users.length,
        iterations: 2,
        startTime: '12s',
        maxDuration: '25s',
    },
    wallet: {
        executor: 'per-vu-iterations',
        exec: 'walletCheck',
        vus: users.length,
        iterations: 2,
        startTime: '16s',
        maxDuration: '25s',
    },
    same_user_earnings: {
        executor: 'per-vu-iterations',
        exec: 'sameUserEarning',
        vus: Number(__ENV.STAGE9_SAME_USER_VUS || 6),
        iterations: 2,
        startTime: '20s',
        maxDuration: '30s',
    },
    many_users_earnings: {
        executor: 'per-vu-iterations',
        exec: 'manyUsersEarning',
        vus: users.length,
        iterations: 1,
        startTime: '24s',
        maxDuration: '30s',
    },
};

const scaleScenarios = {
    scale_public: {
        executor: 'constant-vus',
        exec: 'articleReads',
        vus: Number(__ENV.STAGE9_SCALE_VUS || 8),
        duration: __ENV.STAGE9_SCALE_DURATION || '10s',
        gracefulStop: '3s',
    },
    scale_accounts: {
        executor: 'per-vu-iterations',
        exec: 'accountCheck',
        vus: users.length,
        iterations: 3,
        startTime: '2s',
        maxDuration: '25s',
    },
    scale_wallets: {
        executor: 'per-vu-iterations',
        exec: 'walletCheck',
        vus: users.length,
        iterations: 3,
        startTime: '4s',
        maxDuration: '25s',
    },
};

export const options = {
    discardResponseBodies: false,
    scenarios: suite === 'scale' ? scaleScenarios : coreScenarios,
    thresholds: commonThresholds,
    userAgent: `zrodlo-slowa-stage9-k6/${suite}`,
    noConnectionReuse: false,
};

function userForVu() {
    return users[(__VU - 1) % users.length];
}

function recordInstance(response) {
    const instance = response.headers['X-App-Instance'] || response.headers['x-app-instance'] || 'unknown';
    instanceHits.add(1, { instance });
}

function request(method, path, body = null, params = {}) {
    const response = http.request(method, `${baseUrl}${path}`, body, params);
    recordInstance(response);
    return response;
}

function csrfFrom(response) {
    const match = response.body && response.body.match(/name=["']_csrf["'][^>]*value=["']([^"']+)["']/i);
    return match ? match[1] : '';
}

function login(user) {
    const form = request('GET', '/login', null, { tags: { operation: 'login_form' } });
    const csrf = csrfFrom(form);
    check(form, {
        'formularz logowania HTTP 200': (response) => response.status === 200,
        'formularz logowania zawiera CSRF': () => csrf.length === 64,
    });
    const response = request('POST', '/login', {
        _csrf: csrf,
        email: user.email,
        password: state.password,
    }, {
        redirects: 0,
        tags: { operation: 'login_submit' },
    });
    check(response, {
        'logowanie kończy się przekierowaniem': (result) => result.status === 302 || result.status === 303,
    });
    return response.status === 302 || response.status === 303;
}

function authenticatedPage(user, path, operation) {
    if (!login(user)) {
        return null;
    }
    const response = request('GET', path, null, { tags: { operation } });
    check(response, {
        [`${operation} HTTP 200`]: (result) => result.status === 200,
        [`${operation} pozostaje zalogowane`]: (result) => !String(result.url).includes('/login'),
    });
    return response;
}

function recordEarning(user, referenceId, operation) {
    if (!login(user)) {
        return;
    }
    const account = request('GET', '/account/settings', null, { tags: { operation: `${operation}_csrf` } });
    const csrf = csrfFrom(account);
    check(account, {
        [`${operation} ma token CSRF`]: () => csrf.length === 64,
    });
    const response = request('POST', '/activity/record', {
        _csrf: csrf,
        activity_type: 'bug_report_bonus',
        reference_type: state.reference_type,
        reference_id: referenceId,
        note: `stage9:${state.token}`,
        back: '/account/settings',
    }, {
        redirects: 0,
        tags: { operation },
    });
    check(response, {
        [`${operation} zostało przyjęte`]: (result) => result.status === 302 || result.status === 303,
    });
}

export function articleReads() {
    const list = request('GET', '/articles', null, { tags: { operation: 'article_list' } });
    check(list, { 'lista artykułów HTTP 200': (response) => response.status === 200 });
    const article = request('GET', `/article?id=${state.article_id}`, null, { tags: { operation: 'article_read' } });
    check(article, {
        'czytanie artykułu HTTP 200': (response) => response.status === 200,
        'czytanie zwraca artykuł testowy': (response) => String(response.body).includes(state.article_marker),
    });
    sleep(0.05);
}

export function loginCheck() {
    login(userForVu());
}

export function accountCheck() {
    authenticatedPage(userForVu(), '/account/settings', 'account');
}

export function walletCheck() {
    authenticatedPage(userForVu(), '/wallet', 'wallet');
}

export function sameUserEarning() {
    recordEarning(users[0], state.same_reference_id, 'same_user_earning');
}

export function manyUsersEarning() {
    const index = exec.scenario.iterationInTest % users.length;
    recordEarning(users[index], state.many_reference_ids[index], 'many_users_earning');
}

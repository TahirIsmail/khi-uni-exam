// Browser check of MFA (Step 7c) and the administration screens (Step 7b) in headless Chrome.
//
//   node tests/browser/admin_and_mfa.mjs https://kmu-assess.test
//
// Local development only. Creates a temporary Super Admin and a temporary faculty member in the
// CMS database (MYSQL_CMS), signs in with a ticket signed by the local KMU_CMS_SSO_SECRET, sets up
// an authenticator (codes computed here), opens the admin screens without saving anything, then
// passes the MFA challenge in a fresh session. The temporary staff, their local user, sessions and
// tickets are removed at the end. Audit entries stay: the audit log cannot be edited or deleted.
import { execSync, spawn } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const ASSESS = (process.argv[2] ?? 'https://kmu-assess.test').replace(
    /\/$/,
    '',
);
const MYSQL_CMS = process.env.MYSQL_CMS ?? 'mysql -uroot kmu-cms';
const MYSQL_ASSESS = process.env.MYSQL_ASSESS ?? 'mysql -uroot kmu_assess';
const CHROME =
    process.env.CHROME ??
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const ADMIN_EMAIL = 'admin-mfa-e2e@kmu.local';
const FACULTY_EMAIL = 'faculty-e2e@kmu.local';
const SUPER_ADMIN_ROLE = 7;

const run = (mysql, query) => {
    try {
        return execSync(`${mysql} -N`, {
            encoding: 'utf8',
            input: query,
            stdio: ['pipe', 'pipe', 'pipe'],
        }).trim();
    } catch (error) {
        return `SQL ERROR: ${String(error.stderr).trim()}`;
    }
};
const cms = (q) => run(MYSQL_CMS, q);
const assess = (q) => run(MYSQL_ASSESS, q);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, ok, detail = '') =>
    results.push({ name, ok: Boolean(ok), detail });

// --- SSO ticket, as kmu-cms builds it -------------------------------------------------------
const env = readFileSync(new URL('../../.env', import.meta.url), 'utf8');
const ssoSecret = Buffer.from(
    (env.match(/^KMU_CMS_SSO_SECRET=(.*)$/m) ?? [])[1]
        ?.trim()
        .replace(/^"|"$/g, '') ?? '',
    'base64',
);
const b64url = (buf) =>
    Buffer.from(buf)
        .toString('base64')
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
function ticket(staffId, redirect) {
    const now = Math.floor(Date.now() / 1000);
    const payload = b64url(
        JSON.stringify({
            iss: 'kmu-cms',
            aud: 'kmu-assess',
            sub: Number(staffId),
            iat: now,
            exp: now + 60,
            jti: randomBytes(32).toString('hex'),
            redirect,
        }),
    );
    return `${payload}.${b64url(createHmac('sha256', ssoSecret).update(payload).digest())}`;
}

// --- RFC 6238 TOTP, as an authenticator app computes it --------------------------------------
function base32(text) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of text.replace(/=+$/, '').toUpperCase())
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    return Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));
}
function totp(secret, stepOffset = 0) {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(
        BigInt(Math.floor(Date.now() / 30000) + stepOffset),
    );
    const hmac = createHmac('sha1', base32(secret)).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(
        6,
        '0',
    );
}

// --- temporary CMS staff ---------------------------------------------------------------------
function cleanup() {
    for (const email of [ADMIN_EMAIL, FACULTY_EMAIL]) {
        const id = cms(`SELECT id FROM staff WHERE email = '${email}'`);
        if (!id || id.startsWith('SQL')) continue;
        const userId = assess(
            `SELECT id FROM users WHERE cms_staff_id = ${id}`,
        );
        if (userId)
            assess(
                `DELETE FROM sessions WHERE user_id = ${userId}; DELETE FROM sec_user_scopes WHERE user_id = ${userId}; DELETE FROM users WHERE id = ${userId}`,
            );
        assess(`DELETE FROM sso_consumed_tickets WHERE cms_staff_id = ${id}`);
        cms(
            `DELETE FROM staff_roles WHERE staff_id = ${id}; DELETE FROM staff WHERE id = ${id}`,
        );
    }
    cms(
        'ALTER TABLE staff AUTO_INCREMENT = 1; ALTER TABLE staff_roles AUTO_INCREMENT = 1',
    );
}

cleanup();
const template = cms(
    `SELECT MIN(staff_id) FROM staff_roles WHERE role_id = ${SUPER_ADMIN_ROLE}`,
);
const cols = cms(
    "SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name NOT IN ('id','email','employee_id','name','surname','branch_id')",
);
cms(`SET SESSION sql_mode = '';
    INSERT INTO staff (${cols}, email, employee_id, name, surname, branch_id) SELECT ${cols}, '${ADMIN_EMAIL}', 'TMP-ADMIN-MFA', 'Temp', 'Admin', 1 FROM staff WHERE id = ${template};
    INSERT INTO staff_roles (role_id, staff_id, is_active) VALUES (${SUPER_ADMIN_ROLE}, LAST_INSERT_ID(), 1);
    INSERT INTO staff (${cols}, email, employee_id, name, surname, branch_id) SELECT ${cols}, '${FACULTY_EMAIL}', 'TMP-FACULTY', 'Temp', 'Faculty', 1 FROM staff WHERE id = ${template};`);
const adminStaffId = cms(`SELECT id FROM staff WHERE email = '${ADMIN_EMAIL}'`);
const facultyStaffId = cms(
    `SELECT id FROM staff WHERE email = '${FACULTY_EMAIL}'`,
);

// --- browser ---------------------------------------------------------------------------------
const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9351',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'admin-mfa-e2e-'))}`,
        '--ignore-certificate-errors',
        '--no-first-run',
        '--window-size=1400,1000',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let target;
for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try {
        target = (
            await (await fetch('http://127.0.0.1:9351/json/list')).json()
        ).find((t) => t.type === 'page');
    } catch {}
}
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let nextId = 0;
const pending = new Map();
const jsErrors = [];
ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) {
        pending.get(m.id)(m);
        pending.delete(m.id);
    }
    if (m.method === 'Runtime.exceptionThrown')
        jsErrors.push(
            m.params.exceptionDetails?.exception?.description ??
                m.params.exceptionDetails?.text,
        );
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error')
        jsErrors.push(
            m.params.args.map((a) => a.value ?? a.description).join(' '),
        );
    if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error')
        jsErrors.push(`${m.params.entry.text} ${m.params.entry.url ?? ''}`);
});
const send = (method, params = {}) =>
    new Promise((r) => {
        const id = ++nextId;
        pending.set(id, r);
        ws.send(JSON.stringify({ id, method, params }));
    });
const evaluate = async (expression) =>
    (
        await send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        })
    ).result?.result?.value;
const waitFor = async (expression, tries = 60) => {
    for (let i = 0; i < tries; i++) {
        if (await evaluate(expression)) return true;
        await sleep(250);
    }
    return false;
};
const path = async () => evaluate('location.pathname');
const text = async () => (await evaluate('document.body.innerText')) ?? '';
const load = async (p) => {
    await send('Page.navigate', { url: ASSESS + p });
    await waitFor("document.readyState === 'complete'");
    await sleep(800);
};
const type = async (selector, value) => {
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).focus()`,
    );
    await send('Input.insertText', { text: value });
    await sleep(200);
};
const click = async (selector) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`,
    );
const signInFromCms = async (staffId, redirect) => {
    await send('Page.navigate', { url: 'about:blank' });
    await evaluate(
        `(() => { const f = document.createElement('form'); f.method = 'post'; f.action = '${ASSESS}/sso/cms'; const i = document.createElement('input'); i.name = 'ticket'; i.value = ${JSON.stringify(ticket(staffId, redirect))}; f.appendChild(i); document.body.appendChild(f); f.submit(); return true; })()`,
    );
    await waitFor(
        `location.host === '${new URL(ASSESS).host}' && document.readyState === 'complete'`,
    );
    await sleep(1200);
};

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Log.enable');

    // ---- MFA setup ----
    await signInFromCms(adminStaffId, '/admin/roles');
    check(
        'a Super Admin coming from kmu-cms is sent to set up an authenticator first',
        (await path()) === '/mfa/setup',
        await path(),
    );
    await load('/admin/audit');
    check(
        'admin screens stay closed until MFA is set up',
        (await path()) === '/mfa/setup',
        await path(),
    );

    await click('[data-test=mfa-start]');
    check(
        'starting setup shows a QR code and a setup key',
        await waitFor(
            "!!document.querySelector('[role=img] svg') && !!document.querySelector('[data-test=mfa-setup-key]')",
        ),
    );
    const secret = (
        await evaluate(
            "document.querySelector('[data-test=mfa-setup-key]').textContent",
        )
    ).trim();

    const good = totp(secret);
    await type(
        '[data-input-otp], #otp input, input[autocomplete=one-time-code]',
        good === '000000' ? '111111' : '000000',
    );
    await click('[data-test=mfa-confirm]');
    check(
        'a wrong code is refused with a message',
        await waitFor('/not valid/i.test(document.body.innerText)'),
        (await text()).slice(0, 300),
    );

    await type(
        '[data-input-otp], #otp input, input[autocomplete=one-time-code]',
        good,
    );
    await click('[data-test=mfa-confirm]');
    check(
        'the right code shows 8 recovery codes once',
        await waitFor(
            "document.querySelectorAll('[data-test=mfa-recovery-codes] li').length === 8",
        ),
        await path(),
    );
    check(
        'Continue is disabled until the codes are marked as saved',
        !(await evaluate(
            "!!document.querySelector('[data-test=mfa-recovery-continue]')",
        )),
    );
    await click('#saved');
    await waitFor(
        "!!document.querySelector('[data-test=mfa-recovery-continue]')",
    );
    await click('[data-test=mfa-recovery-continue]');
    check(
        'continuing lands on the last page the user tried to open (audit log)',
        await waitFor("location.pathname === '/admin/audit'"),
        await path(),
    );
    await load('/admin/roles');
    await waitFor("document.body.innerText.includes('Roles & permissions')");
    check(
        'the authenticator is stored as confirmed',
        assess(
            `SELECT two_factor_confirmed_at IS NOT NULL FROM users WHERE cms_staff_id = ${adminStaffId}`,
        ) === '1',
    );

    // ---- admin screens (read-only) ----
    const sidebar = await text();
    check(
        'sidebar shows the Administration links',
        /Administration/.test(sidebar) &&
            /Staff scopes/.test(sidebar) &&
            /Audit log/.test(sidebar),
    );
    check(
        'starter-kit links to external sites are gone',
        !(await evaluate(
            '!!document.querySelector(\'a[href*="github.com"], a[href*="laravel.com/docs"]\')',
        )),
    );
    const roleButtons = await evaluate(
        "[...document.querySelectorAll('ul[aria-label=Roles] button')].map(b => b.innerText)",
    );
    check(
        'roles from kmu-cms are listed, Super Admin first',
        Array.isArray(roleButtons) &&
            /Super Admin/.test(roleButtons[0]) &&
            roleButtons.some((r) => /Faculty/.test(r)),
        JSON.stringify(roleButtons?.slice(0, 3)),
    );
    check(
        'every catalogue permission has a checkbox',
        (await evaluate(
            "document.querySelectorAll('[data-permission]').length",
        )) === 50,
    );
    await evaluate(
        "[...document.querySelectorAll('ul[aria-label=Roles] button')].find(b => /Faculty/.test(b.innerText)).click()",
    );
    await sleep(400);
    check(
        'a normal role is editable for a Super Admin',
        await evaluate(
            "!document.querySelector('[data-permission=\"qbank.question.create\"]').disabled && !!document.querySelector('[data-test=save-role-permissions]')",
        ),
    );
    await click('[data-permission="qbank.question.create"]');
    await sleep(300);
    check(
        'ticking a permission enables Save (not submitted)',
        await evaluate(
            "!document.querySelector('[data-test=save-role-permissions]').disabled",
        ),
    );
    await evaluate(
        "[...document.querySelectorAll('ul[aria-label=Roles] button')].find(b => /Super Admin/.test(b.innerText)).click()",
    );
    await sleep(400);
    check(
        'the Super Admin role is read-only',
        await evaluate(
            "!document.querySelector('[data-test=save-role-permissions]') && document.querySelector('[data-permission=\"exam.publish\"]').disabled",
        ),
    );

    await load('/admin/staff');
    check(
        'staff list shows kmu-cms staff of the campus',
        /Temp Faculty/.test(await text()),
    );
    await type('input[type=search]', 'no-such-person-xyz');
    await send('Input.dispatchKeyEvent', {
        type: 'keyDown',
        key: 'Enter',
        code: 'Enter',
        windowsVirtualKeyCode: 13,
        text: '\r',
    });
    await send('Input.dispatchKeyEvent', {
        type: 'keyUp',
        key: 'Enter',
        code: 'Enter',
        windowsVirtualKeyCode: 13,
    });
    check(
        'search with no match says so',
        await waitFor("document.body.innerText.includes('No staff found')"),
    );

    await load(`/admin/staff/${facultyStaffId}`);
    const scopeText = await text();
    check(
        'scopes page for a staff member who has not signed in',
        /Temp Faculty/.test(scopeText) &&
            /Main Campus/.test(scopeText) &&
            /No scopes/.test(scopeText),
        scopeText.slice(0, 300),
    );
    await evaluate(
        "(() => { const s = document.getElementById('scope_type'); s.value = 'programme'; s.dispatchEvent(new Event('change')); return true; })()",
    );
    await sleep(300);
    const programmeOptions = await evaluate(
        "[...document.querySelectorAll('#scope_id option')].map(o => o.textContent.trim())",
    );
    check(
        'programme choices come from kmu-cms (MBBS, BDS, DPT)',
        ['MBBS', 'BDS', 'DPT'].every((p) =>
            programmeOptions?.some((o) => o.startsWith(p)),
        ),
        JSON.stringify(programmeOptions),
    );
    check(
        'opening the page did not create a local user',
        assess(
            `SELECT COUNT(*) FROM users WHERE cms_staff_id = ${facultyStaffId}`,
        ) === '0',
    );
    await load(`/admin/staff/${adminStaffId}`);
    check(
        'your own scopes page is refused',
        /403|forbidden/i.test(await text()),
    );

    await load('/admin/audit');
    const auditText = await text();
    check(
        'audit log shows the sign-in and the MFA enrolment',
        /identity\.sso\.login/.test(auditText) &&
            /identity\.mfa\.enrolled/.test(auditText),
    );
    await evaluate(
        "[...document.querySelectorAll('button')].find(b => b.innerText === 'Details').click()",
    );
    check(
        'an entry expands to show its details',
        await waitFor("document.body.innerText.includes('IP address')"),
    );
    await click('[data-test=verify-chain]');
    check(
        'chain check reports the log intact',
        await waitFor(
            '/Intact: all \\d+ entries/.test(document.body.innerText)',
        ),
        (await text()).slice(0, 200),
    );
    check(
        'export link points to the CSV export',
        (await evaluate(
            "document.querySelector('[data-test=export-audit]')?.getAttribute('href')",
        )) === '/admin/audit/export',
    );
    await load("/admin/audit?action=x'%20OR%201=1");
    check(
        'a malformed filter is rejected before any query (redirected back without it)',
        (await evaluate('location.pathname + location.search')) ===
            '/admin/audit',
    );

    // ---- MFA challenge in a fresh session ----
    await send('Network.clearBrowserCookies');
    await signInFromCms(adminStaffId, '/admin/staff');
    check(
        'in a new session the enrolled admin must pass the challenge',
        (await path()) === '/mfa/challenge',
        await path(),
    );
    // The current code was used during setup; the next 30-second code is accepted once.
    await type(
        '[data-input-otp], #otp input, input[autocomplete=one-time-code]',
        totp(secret, 1),
    );
    await click('[data-test=mfa-continue]');
    check(
        'a valid code opens the page the admin was going to',
        await waitFor("location.pathname === '/admin/staff'"),
        await path(),
    );

    check(
        'no JavaScript or CSP errors on any screen',
        jsErrors.filter((e) => !/403|Forbidden|422|Unprocessable/i.test(e))
            .length === 0,
        jsErrors.join(' | '),
    );
} finally {
    ws.close();
    chrome.kill();
    cleanup();
}

const left =
    cms(
        `SELECT COUNT(*) FROM staff WHERE email IN ('${ADMIN_EMAIL}', '${FACULTY_EMAIL}')`,
    ) +
    '/' +
    assess(
        `SELECT COUNT(*) FROM users WHERE email IN ('${ADMIN_EMAIL}', '${FACULTY_EMAIL}')`,
    );
check('temporary staff and users removed', left === '0/0', left);

for (const r of results)
    console.log(
        `${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.detail && !r.ok ? `  (${String(r.detail).slice(0, 300)})` : ''}`,
    );
console.log(
    `\npassed: ${results.filter((r) => r.ok).length}, failed: ${results.filter((r) => !r.ok).length}`,
);
process.exit(results.every((r) => r.ok) ? 0 : 1);
